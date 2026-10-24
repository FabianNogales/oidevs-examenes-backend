<?php

namespace Tests\Feature\Api\V1\TeacherDashboard;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class TeacherUpcomingExamsTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private const URL = '/api/v1/teacher/dashboard/upcoming-exams';

    private array $fixture;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite'
            || $connection['database'] !== ':memory:' || ! empty($connection['url'])
            || $app['config']->get('session.driver') !== 'array' || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Dashboard tests require isolated in-memory SQLite, array sessions/cache and no cached configuration.');
        }
        $pdo = $app['db']->connection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \RuntimeException('Unexpected PDO driver.');
        }
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '') {
                throw new \RuntimeException('Persistent test database rejected.');
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        Sanctum::actingAs($this->fixture['user']);
    }

    private function exam(array $attributes = []): Exam
    {
        return Exam::create($attributes + [
            'course_offering_id' => $this->fixture['offering']->id,
            'room_id' => $this->fixture['room']->id, 'created_by' => $this->fixture['user']->id,
            'name' => 'Examen de prueba', 'exam_date' => '2026-10-24',
            'start_time' => '11:00:00', 'duration_minutes' => 60,
            'evaluation_type' => 'partial', 'status' => 'SCHEDULED',
        ]);
    }

    public static function temporalCases(): array
    {
        return [
            'future scheduled' => ['2026-10-24 10:00:00-04:00', '2026-10-25', '10:00:00', 60, 'SCHEDULED', true],
            'legacy active' => ['2026-10-24 10:00:00-04:00', '2026-10-25', '10:00:00', 60, 'ACTIVE', true],
            'in progress state' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '09:30:00', 60, 'IN_PROGRESS', true],
            'scheduled but already started' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '09:30:00', 60, 'SCHEDULED', true],
            'starts exactly now' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '10:00:00', 60, 'SCHEDULED', true],
            'cancelled today' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '11:00:00', 60, 'CANCELLED', false],
            'cancelled tomorrow' => ['2026-10-24 10:00:00-04:00', '2026-10-25', '11:00:00', 60, 'CANCELLED', false],
            'ended one minute ago' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '08:59:00', 60, 'SCHEDULED', false],
            'ends exactly now' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '09:00:00', 60, 'SCHEDULED', false],
            'ends in one minute' => ['2026-10-24 10:00:00-04:00', '2026-10-24', '09:01:00', 60, 'SCHEDULED', true],
            'overnight ended' => ['2026-10-24 00:10:00-04:00', '2026-10-23', '23:00:00', 60, 'SCHEDULED', false],
            'overnight ongoing' => ['2026-10-24 00:10:00-04:00', '2026-10-23', '23:30:00', 120, 'SCHEDULED', true],
            'UTC next day Bolivia today' => ['2026-10-25 01:00:00+00:00', '2026-10-24', '22:00:00', 60, 'SCHEDULED', true],
            'UTC next day already expired locally' => ['2026-10-25 01:00:00+00:00', '2026-10-24', '19:59:00', 60, 'SCHEDULED', false],
            'institutional midnight ongoing' => ['2026-10-25 04:00:00+00:00', '2026-10-24', '23:30:00', 60, 'IN_PROGRESS', true],
            'seconds exactly equal' => ['2026-10-24 10:00:15-04:00', '2026-10-24', '09:00:15', 60, 'SCHEDULED', false],
            'one second remaining' => ['2026-10-24 10:00:15-04:00', '2026-10-24', '09:00:16', 60, 'SCHEDULED', true],
            'completed state even with future date' => ['2026-10-24 10:00:00-04:00', '2026-10-25', '10:00:00', 60, 'COMPLETED', false],
            'finished state even with future date' => ['2026-10-24 10:00:00-04:00', '2026-10-25', '10:00:00', 60, 'FINISHED', false],
        ];
    }

    #[DataProvider('temporalCases')]
    public function test_dashboard_obeys_state_full_end_instant_and_institutional_timezone(
        string $now, string $day, string $start, int $minutes, string $status, bool $visible
    ): void {
        // Keep the application in UTC: the query must explicitly use institutional time.
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse($now));
        $exam = $this->exam(['exam_date' => $day, 'start_time' => $start,
            'duration_minutes' => $minutes, 'status' => $status]);
        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount($visible ? 1 : 0, 'data');
        $this->assertSame($visible ? [$exam->id] : [], array_column($response->json('data'), 'id'));
        $this->assertSame($status, $exam->fresh()->status);
    }

    public function test_reported_cancelled_and_expired_ids_are_absent_but_valid_exam_remains(): void
    {
        $this->exam(['id' => 9, 'status' => 'CANCELLED']);
        $this->exam(['id' => 10, 'start_time' => '08:00:00', 'duration_minutes' => 60]);
        $valid = $this->exam(['id' => 11]);
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $valid->id)
            ->assertJsonPath('data.0.subject_code', 'INF-01')->assertJsonPath('data.0.room.code', 'A-01')
            ->assertJsonPath('data.0.status', 'SCHEDULED')->assertJsonPath('data.0.start_time', '11:00:00');
    }

    public function test_dashboard_only_includes_the_authenticated_teachers_exams(): void
    {
        $otherUser = User::factory()->create();
        $otherTeacher = Teacher::factory()->create(['user_id' => $otherUser->id]);
        $otherOffering = CourseOffering::create(['teacher_id' => $otherTeacher->id,
            'subject_id' => $this->fixture['subject']->id, 'academic_term_id' => $this->fixture['term']->id, 'status' => 'ACTIVE']);
        $this->exam(['course_offering_id' => $otherOffering->id]);
        $own = $this->exam();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    }

    public function test_invalid_earlier_exams_do_not_displace_valid_results_and_order_is_chronological(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->exam(['start_time' => '08:00:00']);
            $this->exam(['status' => 'CANCELLED']);
        }
        $tomorrow = $this->exam(['exam_date' => '2026-10-25']);
        $later = $this->exam(['start_time' => '11:00:02']);
        $earlier = $this->exam(['start_time' => '11:00:01']);
        $this->assertSame([$earlier->id, $later->id, $tomorrow->id],
            array_column($this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data')->json('data'), 'id'));
    }

    public function test_management_retains_cancelled_and_historical_records_after_dashboard_filtering(): void
    {
        $cancelled = $this->exam(['status' => 'CANCELLED']);
        $expired = $this->exam(['start_time' => '08:00:00']);
        $future = $this->exam();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $future->id);
        $this->getJson('/api/v1/teacher/exams?view=cancelled')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $cancelled->id);
        $this->getJson('/api/v1/teacher/exams?view=past')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $expired->id);
        $response = $this->getJson('/api/v1/teacher/exams?view=all')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertEqualsCanonicalizing([$cancelled->id, $expired->id, $future->id], array_column($response->json('data'), 'id'));
    }
}
