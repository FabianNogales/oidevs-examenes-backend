<?php

namespace Tests\Feature\Infractions;

use App\Models\Infraction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InfractionFixtures;
use Tests\TestCase;

class InfractionConcurrencyTest extends TestCase
{
    use InfractionFixtures;

    private array $infraction;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU30_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu30_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a disposable hu30_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createIncidentFixtures();
        $this->infraction = $this->actingAs($this->owner)->postJson($this->infractionBase(), $this->infractionPayload())
            ->assertCreated()->json('data');
    }

    private function operation(array $payload, bool $status = false): array
    {
        return ['user_id' => $this->owner->id, 'exam_id' => $this->exam->id, 'clock' => now()->toISOString(),
            'path' => $this->infractionBase().'/'.$this->infraction['id'].($status ? '/status' : ''), 'method' => 'PATCH',
            'payload' => $payload + ['expected_revision' => $this->infraction['revision']]];
    }

    public function test_simultaneous_edits_with_same_revision_apply_once_without_lost_update(): void
    {
        [$first, $second] = $this->workers($this->operation(['description' => 'Primera edición.']), $this->operation(['description' => 'Segunda edición.']));
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('INFRACTION_VERSION_CONFLICT', $second['data']['code']);
        $this->assertDatabaseHas('infractions', ['id' => $this->infraction['id'], 'description' => 'Primera edición.']);
        $this->assertSame(2, Infraction::findOrFail($this->infraction['id'])->changes()->count());
    }

    public function test_two_confirmations_only_record_one_authorized_decision(): void
    {
        $operation = $this->operation(['status' => 'CONFIRMED', 'reason' => 'Evidencia revisada.'], status: true);
        [$first, $second] = $this->workers($operation, $operation);
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'INFRACTION_STATUS_CHANGED')->count());
        $this->assertDatabaseHas('infractions', ['id' => $this->infraction['id'], 'status' => 'CONFIRMED']);
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_edit_winning_lock_rejects_status_decision_with_stale_revision(): void
    {
        [$first, $second] = $this->workers($this->operation(['observations' => 'Nueva evidencia.']),
            $this->operation(['status' => 'CONFIRMED', 'reason' => 'Decisión sobre datos anteriores.'], status: true));
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertDatabaseHas('infractions', ['id' => $this->infraction['id'], 'status' => 'REPORTED', 'observations' => 'Nueva evidencia.']);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'INFRACTION_STATUS_CHANGED')->count());
    }

    public function test_resolution_winning_lock_rejects_stale_edit_and_preserves_initial_responsible(): void
    {
        [$first, $second] = $this->workers($this->operation(['status' => 'RESOLVED', 'reason' => 'Caso revisado.'], status: true),
            $this->operation(['description' => 'No sobrescribir.']));
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertDatabaseHas('infractions', ['id' => $this->infraction['id'], 'status' => 'RESOLVED',
            'description' => $this->infractionPayload()['description'], 'reported_by' => $this->owner->id]);
        $this->assertSame(2, Infraction::findOrFail($this->infraction['id'])->changes()->count());
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu30_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu30_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/InfractionConcurrencyWorker.php'), $ready, $paths[$index], $index === 0 ? '1' : '0'],
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
