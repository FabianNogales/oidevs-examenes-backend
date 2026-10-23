<?php

namespace Tests\Feature\Api\V1\Collaborators;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\StudentQrToken;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class CollaborationConcurrencyTest extends TestCase
{
    use CourseOfferingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EIDA_FIX010_PG_CONCURRENCY') !== '1' || ! $this->app->environment('testing')
            || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^fix010_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())
            || config('database.connections.pgsql.url') !== null
            || config('cache.default') !== 'array' || config('session.driver') !== 'array'
            || $this->app->configurationIsCached()
            || realpath(storage_path()) === realpath(base_path('storage'))) {
            $this->markTestSkipped('Requires explicit opt-in, isolated fix010_test_* PostgreSQL and temporary storage.');
        }
        $pdo = DB::connection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || $pdo->query('SELECT current_database()')->fetchColumn() !== DB::connection()->getDatabaseName()) {
            throw new \RuntimeException('The actual PDO database does not match the isolated test configuration.');
        }
        // Only the explicitly isolated database above may be refreshed by this test.
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public static function races(): array
    {
        return [
            'two assignments' => ['assign_assign', [201, 422], 'ACTIVE', 1, 0],
            'two revocations' => ['revoke_revoke', [200, 404], 'REVOKED', 2, 0],
            'stale revoke after reassignment' => ['revoke_reassign_revoke', [200, 404], 'ACTIVE', 3, 0],
            'revoke before QR' => ['revoke_qr', [200, 403], 'REVOKED', 2, 0],
            'QR before revoke' => ['qr_revoke', [201, 200], 'REVOKED', 2, 1],
            'revoke before manual' => ['revoke_manual', [200, 403], 'REVOKED', 2, 0],
            'manual before revoke' => ['manual_revoke', [201, 200], 'REVOKED', 2, 1],
            'cancel before assignment' => ['cancel_assign', [200, 422], null, 0, 0],
            'inactive candidate before assignment' => ['inactive_assign', [200, 422], null, 0, 0],
            'independent exams' => ['different_exams', [201, 201], 'ACTIVE', 2, 0],
        ];
    }

    #[DataProvider('races')]
    public function test_real_postgresql_race(
        string $scenario, array $statuses, ?string $status, int $events, int $entries,
    ): void {
        $this->travelTo(now()->startOfSecond());
        $fixture = $this->offeringFixture();
        $exam = Exam::create($this->examPayload($fixture['room']->id) + [
            'course_offering_id' => $fixture['offering']->id, 'created_by' => $fixture['user']->id, 'status' => 'SCHEDULED',
        ]);
        if (str_contains($scenario, 'qr') || str_contains($scenario, 'manual')) {
            // Ingress races run at the inclusive start; cancellation scenarios remain future.
            $exam->update(['exam_date' => now('America/La_Paz')->toDateString(),
                'start_time' => now('America/La_Paz')->format('H:i:s')]);
        }
        $collaborator = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $base = '/api/v1/exams/'.$exam->id;
        $assign = ['method' => 'POST', 'path' => $base.'/collaborators', 'payload' => ['user_id' => $collaborator->id], 'actor' => $fixture['user']->id];
        $revoke = ['method' => 'DELETE', 'path' => $base.'/collaborators/'.$collaborator->id, 'payload' => [], 'actor' => $fixture['user']->id];
        $scan = ['method' => 'POST', 'path' => $base.'/entry-control/qr', 'payload' => ['room_id' => $exam->room_id],
            'actor' => $collaborator->id, 'qr_student_id' => $fixture['studentId'], 'qr_exam_id' => $exam->id];
        $manual = ['method' => 'POST', 'path' => $base.'/entry-control/students/'.$fixture['studentId'].'/entries',
            'payload' => ['room_id' => $exam->room_id], 'actor' => $collaborator->id];
        if (str_contains($scenario, 'revoke')) {
            $this->actingAs($fixture['user'])->postJson($assign['path'], $assign['payload'])->assertCreated();
        }
        DB::table('enrollments')->insert(['student_id' => $fixture['studentId'], 'course_offering_id' => $fixture['offering']->id,
            'registered_by' => $fixture['user']->id, 'status' => 'ACTIVE']);
        DB::table('exam_eligibilities')->insert(['exam_id' => $exam->id, 'student_id' => $fixture['studentId'], 'status' => 'ELIGIBLE']);
        StudentQrToken::create(['exam_id' => $exam->id, 'student_id' => $fixture['studentId'],
            'token' => (string) Str::uuid(), 'status' => 'ACTIVE', 'generated_at' => now()]);
        $cancel = ['method' => 'POST', 'path' => '/api/v1/teacher/exams/'.$exam->id.'/cancel',
            'payload' => ['expected_updated_at' => $exam->fresh()->updated_at->toISOString()], 'actor' => $fixture['user']->id];
        [$first, $second] = match ($scenario) {
            'assign_assign' => [[$assign], [$assign]],
            'revoke_revoke' => [[$revoke], [$revoke]],
            'revoke_reassign_revoke' => [[$revoke, $assign], [$revoke]],
            'revoke_qr' => [[$revoke], [$scan]],
            'qr_revoke' => [[$scan], [$revoke]],
            'revoke_manual' => [[$revoke], [$manual]],
            'manual_revoke' => [[$manual], [$revoke]],
            'cancel_assign' => [[$cancel], [$assign]],
            'inactive_assign' => [[['deactivate_user' => $collaborator->id]], [$assign]],
            'different_exams' => [[$assign], [$assign]],
        };
        if ($scenario === 'different_exams') {
            $other = $exam->replicate();
            $other->save();
            $second[0]['path'] = '/api/v1/exams/'.$other->id.'/collaborators';
        }
        $results = $this->workers($exam->id, $first, $second, $scenario === 'different_exams');
        $this->assertSame($statuses, array_column($results, 'status'));
        if (count($first) === 2) {
            $this->assertSame([200, 201], $results[0]['statuses']);
        }
        $rows = ExamCollaborator::where('exam_id', $exam->id)->get();
        $this->assertCount($status === null ? 0 : 1, $rows);
        if ($status !== null) {
            $this->assertSame($status, $rows->sole()->status);
        }
        $this->assertSame($events, AuditLog::where('entity_type', 'ExamCollaborator')->count());
        $this->assertDatabaseCount('exam_entries', $entries);
        if ($scenario === 'revoke_reassign_revoke') {
            $logs = AuditLog::where('entity_type', 'ExamCollaborator')->orderBy('id')->get();
            $this->assertSame($logs[0]->id, $logs[1]->new_values['opening_event_id']);
            $this->assertSame('EXAM_COLLABORATOR_REASSIGNED', $logs[2]->action);
        }
    }

    private function workers(int $examId, array $first, array $second, bool $independent): array
    {
        $prefix = storage_path('framework/cache/fix010_'.Str::random(16));
        $processes = $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operations) {
                $path = $prefix.'.'.$index.'.json';
                file_put_contents($path, json_encode(['exam_id' => $examId, 'clock' => now()->toISOString(),
                    'operations' => $operations, 'independent' => $independent], JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/CollaborationConcurrencyWorker.php'),
                    $prefix, $path, (string) $index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$index], base_path());
                $this->assertIsResource($processes[$index]);
                fclose($pipes[$index][0]);
                stream_set_timeout($pipes[$index][1], 25);
                stream_set_timeout($pipes[$index][2], 25);
            }
            $results = [];
            foreach ($processes as $index => $process) {
                $output = stream_get_contents($pipes[$index][1]);
                $error = stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $this->assertSame(0, proc_close($process), $error.' '.$output);
                unset($processes[$index]);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (['.0.json', '.1.json', '.ready', '.waiting', '.done'] as $suffix) {
                if (is_file($prefix.$suffix)) {
                    unlink($prefix.$suffix);
                }
            }
        }
    }
}
