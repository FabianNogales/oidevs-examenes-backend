<?php

namespace Tests\Feature\Incidents;

use App\Models\ExamCollaborator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentConcurrencyTest extends TestCase
{
    use IncidentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU27_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu27_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a disposable hu27_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/La_Paz'));
        $this->createIncidentFixtures();
    }

    private function report(User $actor, string $description = 'Documento por verificar.'): array
    {
        return $this->operation($actor, $this->url(), 'POST', ['type' => 'IDENTIFICATION', 'description' => $description]);
    }

    private function revoke(User $collaborator): array
    {
        return $this->operation($this->owner,
            '/api/v1/exams/'.$this->exam->id.'/collaborators/'.$collaborator->id, 'DELETE');
    }

    private function operation(User $actor, string $path, string $method, array $payload = []): array
    {
        return ['user_id' => $actor->id, 'exam_id' => $this->exam->id, 'clock' => now()->toISOString(),
            'path' => $path, 'method' => $method, 'payload' => $payload, 'lock' => 'exam'];
    }

    public function test_revocation_winning_lock_prevents_a_waiting_report(): void
    {
        $collaborator = $this->collaborator();
        [$first, $second] = $this->workers($this->revoke($collaborator), $this->report($collaborator));
        $this->assertSame(200, $first['status']);
        $this->assertSame(403, $second['status']);
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
        $this->assertSame('REVOKED', ExamCollaborator::where('user_id', $collaborator->id)->value('status'));
    }

    public function test_report_winning_lock_is_preserved_when_collaborator_is_revoked_afterwards(): void
    {
        $collaborator = $this->collaborator();
        [$first, $second] = $this->workers($this->report($collaborator), $this->revoke($collaborator));
        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertDatabaseCount('special_cases', 1);
        $this->assertDatabaseHas('special_cases', ['reported_by' => $collaborator->id, 'status' => 'PENDING']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
        $this->actingAs($collaborator)->getJson($this->url())->assertForbidden();
    }

    public function test_simultaneous_manual_reports_preserve_both_events_and_their_audits(): void
    {
        $collaborator = $this->collaborator();
        [$first, $second] = $this->workers($this->report($this->owner, 'Primer reporte.'), $this->report($collaborator, 'Segundo reporte.'));
        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertNotSame($first['data']['data']['id'], $second['data']['data']['id']);
        $this->assertDatabaseCount('special_cases', 2);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
        $this->assertDatabaseCount('infractions', 0);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_parallel_reads_of_same_qr_event_never_materialize_duplicate_incidents(): void
    {
        DB::table('audit_logs')->insert(['user_id' => $this->owner->id, 'action' => 'ENTRY_QR_WRONG_EXAM',
            'entity_type' => 'Exam', 'entity_id' => $this->exam->id, 'created_at' => now(),
            'new_values' => json_encode(['student_id' => $this->student->id, 'qr_exam_id' => 987, 'reason' => 'WRONG_EXAM'])]);
        $read = $this->operation($this->owner, $this->url(), 'GET');
        [$first, $second] = $this->workers($read, $read);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, $first['data']['meta']['total']);
        $this->assertSame($first['data']['data'][0]['id'], $second['data']['data'][0]['id']);
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ENTRY_QR_WRONG_EXAM')->count());
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu27_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu27_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/IncidentConcurrencyWorker.php'), $ready, $paths[$index], $index === 0 ? '1' : '0'],
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
