<?php

namespace Tests\Feature\Api\V1\Exams;

use App\Models\Exam;
use App\Services\Exams\ExamScheduleTime;
use App\Services\Rooms\RoomAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class ExamSchedulingTimeTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    public function createApplication()
    {
        $app = parent::createApplication();
        // Fail before RefreshDatabase can run migrations on any other database.
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new \LogicException('These tests require SQLite :memory: without a DB_URL override.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 22:15:40', ExamScheduleTime::TIMEZONE));
        $this->fixture = $this->offeringFixture();
        Sanctum::actingAs($this->fixture['user']);
    }

    public static function startTimes(): array
    {
        return [
            'reported 80 seconds ahead' => ['2026-10-21T22:15:40-04:00', '2026-10-21', '22:17:00', true],
            'one minute ahead' => ['2026-10-21T22:15:00-04:00', '2026-10-21', '22:16:00', true],
            'two minutes ahead' => ['2026-10-21T22:15:00-04:00', '2026-10-21', '22:17:00', true],
            'exactly now' => ['2026-10-21T22:17:00-04:00', '2026-10-21', '22:17:00', false],
            'past minute' => ['2026-10-21T22:18:00-04:00', '2026-10-21', '22:17:00', false],
            'previous date' => ['2026-10-22T00:00:00-04:00', '2026-10-21', '23:59:00', false],
            'midnight start' => ['2026-10-21T23:59:40-04:00', '2026-10-22', '00:00:00', true],
            'microsecond before' => ['2026-10-21T22:16:59.999999-04:00', '2026-10-21', '22:17:00', true],
            'microsecond after' => ['2026-10-21T22:17:00.000001-04:00', '2026-10-21', '22:17:00', false],
        ];
    }

    #[DataProvider('startTimes')]
    public function test_creation_uses_the_server_instant_and_never_persists_rejected_starts(string $now, string $date, string $time, bool $allowed): void
    {
        // UTC remains the application default; scheduling must still use La Paz.
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse($now));
        $response = $this->postJson($this->endpoint(), $this->payload($date, $time));

        if (! $allowed) {
            $response->assertUnprocessable()->assertJsonValidationErrors('start_time');
            $this->assertDatabaseCount('exams', 0);
            $this->assertDatabaseCount('exam_eligibilities', 0);

            return;
        }

        $response->assertCreated()->assertJsonPath('data.exam_date', $date)->assertJsonPath('data.start_time', $time);
        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseHas('exams', ['exam_date' => $date, 'start_time' => $time, 'duration_minutes' => 90]);
        $exam = Exam::firstOrFail();
        [$start, $end] = app(RoomAvailabilityService::class)->interval($exam->exam_date, $exam->start_time, $exam->duration_minutes);
        $this->assertSame(90 * 60, $end->getTimestamp() - $start->getTimestamp());
    }

    public static function reservationDelays(): array
    {
        return [
            'lock released exactly at start' => ['2026-10-21T22:17:00-04:00'],
            'lock released after start' => ['2026-10-21T22:17:01-04:00'],
        ];
    }

    #[DataProvider('reservationDelays')]
    public function test_start_is_rechecked_after_reservation_and_before_insert(string $afterReservation): void
    {
        $availability = new RoomAvailabilityService;
        $this->mock(RoomAvailabilityService::class, function (MockInterface $mock) use ($availability, $afterReservation) {
            $mock->shouldReceive('ensureReservable')->once()->andReturnUsing(function (...$args) use ($availability, $afterReservation) {
                $availability->ensureReservable(...$args);
                // Simulate elapsed lock time without sleeping or changing reservation rules.
                $this->travelTo(CarbonImmutable::parse($afterReservation));
            });
        });

        $this->postJson($this->endpoint(), $this->payload('2026-10-21', '22:17:00'))
            ->assertUnprocessable()->assertJsonValidationErrors('start_time')
            ->assertJsonPath('errors.start_time.0', ExamScheduleTime::FUTURE_START_ERROR);
        $this->assertDatabaseCount('exams', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'Exam')->where('action', 'WRITE')->count());
    }

    public function test_invalid_temporal_formats_are_still_rejected(): void
    {
        foreach ([['exam_date', '2026-02-30'], ['exam_date', '21/10/2026'], ['start_time', '22:17'], ['start_time', '24:00:00']] as [$field, $value]) {
            $payload = $this->payload('2026-10-21', '22:17:00');
            $payload[$field] = $value;
            $this->postJson($this->endpoint(), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('exams', 0);
    }

    private function endpoint(): string
    {
        return '/api/v1/course-offerings/'.$this->fixture['offering']->id.'/exams';
    }

    private function payload(string $date, string $time): array
    {
        return array_replace($this->examPayload($this->fixture['room']->id), [
            'exam_date' => $date, 'start_time' => $time, 'duration_minutes' => 90,
        ]);
    }
}
