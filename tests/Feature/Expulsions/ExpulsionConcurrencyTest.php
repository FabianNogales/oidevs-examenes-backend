<?php

namespace Tests\Feature\Expulsions;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExpulsionFixtures;
use Tests\TestCase;

class ExpulsionConcurrencyTest extends TestCase
{
    use ExpulsionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU31_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu31_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a disposable hu31_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createIncidentFixtures();
    }

    private function operation(string $path, string $method, array $payload): array
    {
        return ['user_id' => $this->owner->id, 'exam_id' => $this->exam->id, 'clock' => now()->toISOString(),
            'path' => $path, 'method' => $method, 'payload' => $payload];
    }

    public function test_simultaneous_expulsions_of_same_student_only_create_one_record_and_audit(): void
    {
        [$first, $second] = $this->workers($this->operation($this->expulsionBase(), 'POST', $this->expulsionPayload()),
            $this->operation($this->expulsionBase(), 'POST', array_replace($this->expulsionPayload(), ['reason' => 'No reemplazar.'])));
        $this->assertSame(201, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('STUDENT_ALREADY_EXPELLED', $second['data']['code']);
        $this->assertDatabaseCount('expulsions', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
        $this->assertDatabaseHas('expulsions', ['student_id' => $this->student->id, 'reason' => $this->expulsionPayload()['reason']]);
    }

    public function test_parallel_expulsions_of_different_students_preserve_both_events(): void
    {
        $student = $this->student->replicate();
        $student->user_id = User::factory()->create()->id;
        $student->sis_code = '87654321';
        $student->identity_number = '87654321';
        $student->save();
        DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id,
            'student_id' => $student->id, 'registered_by' => $this->owner->id, 'status' => 'ACTIVE']);
        [$first, $second] = $this->workers($this->operation($this->expulsionBase(), 'POST', $this->expulsionPayload()),
            $this->operation($this->expulsionBase(), 'POST', array_replace($this->expulsionPayload(), ['student_id' => $student->id])));
        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertDatabaseCount('expulsions', 2);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
    }

    public function test_entry_registration_followed_by_expulsion_preserves_original_attendance(): void
    {
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'status' => 'ELIGIBLE']);
        $path = '/api/v1/exams/'.$this->exam->id.'/entry-control/students/'.$this->student->id.'/entries';
        [$first, $second] = $this->workers($this->operation($path, 'POST', ['room_id' => $this->exam->room_id]),
            $this->operation($this->expulsionBase(), 'POST', $this->expulsionPayload()));
        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertDatabaseHas('exam_entries', ['id' => $first['data']['data']['entry']['id'],
            'student_id' => $this->student->id, 'status' => 'VALID', 'annulled_at' => null]);
        $this->assertDatabaseCount('expulsions', 1);
        $this->actingAs($this->owner)->getJson('/api/v1/exams/'.$this->exam->id.'/entry-control/students')->assertOk()
            ->assertJsonPath('summary.registered', 1)->assertJsonPath('data.0.is_expelled', true);
    }

    public function test_infraction_decision_and_linked_expulsion_keep_separate_auditable_events(): void
    {
        $infraction = $this->actingAs($this->owner)->postJson($this->infractionBase(), $this->infractionPayload())->assertCreated()->json('data');
        [$first, $second] = $this->workers($this->operation($this->infractionBase().'/'.$infraction['id'].'/status', 'PATCH', [
            'expected_revision' => $infraction['revision'], 'status' => 'CONFIRMED', 'reason' => 'Evidencia revisada.',
        ]), $this->operation($this->expulsionBase(), 'POST', $this->expulsionPayload() + ['infraction_id' => $infraction['id']]));
        $this->assertSame(200, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertDatabaseHas('expulsions', ['student_id' => $this->student->id, 'infraction_id' => $infraction['id']]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'INFRACTION_STATUS_CHANGED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu31_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu31_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/ExpulsionConcurrencyWorker.php'), $ready, $paths[$index], $index === 0 ? '1' : '0'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$index], base_path());
                fclose($pipes[$index][0]);
            }
            $results = [];
            foreach ($processes as $index => $process) {
                $output = stream_get_contents($pipes[$index][1]);
                $error = stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $this->assertSame(0, proc_close($process), $error.' '.$output);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            @unlink($ready);
            @unlink($ready.'.contender');
        }
    }
}
