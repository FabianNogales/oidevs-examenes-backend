<?php

namespace Tests\Feature\Entries;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class EntryAnnulmentConcurrencyTest extends TestCase
{
    use EntryAnnulmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU28_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu28_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a disposable hu28_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createIncidentFixtures();
    }

    private function operation(string $path, array $payload, ?User $actor = null): array
    {
        return ['user_id' => ($actor ?? $this->owner)->id, 'exam_id' => $this->exam->id,
            'clock' => now()->toISOString(), 'path' => $path, 'method' => 'POST', 'payload' => $payload];
    }

    private function annulOperation(int $id, string $reason = 'Registro incorrecto.'): array
    {
        return $this->operation($this->entryBase().'/entries/'.$id.'/annulment', ['reason' => $reason]);
    }

    public function test_two_annulments_apply_once_and_keep_first_reason_and_audit(): void
    {
        $id = $this->initialEntry();
        [$first, $second] = $this->workers($this->annulOperation($id, 'Primer motivo.'), $this->annulOperation($id, 'Segundo motivo.'));
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('ENTRY_NOT_VALID', $second['data']['code']);
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'ANNULLED', 'annulment_reason' => 'Primer motivo.']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_annulment_winning_lock_allows_waiting_reentry_without_losing_original(): void
    {
        $id = $this->initialEntry();
        [$first, $second] = $this->workers($this->annulOperation($id), $this->operation($this->manualPath(), ['room_id' => $this->exam->room_id]));
        $this->assertSame(200, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'ANNULLED']);
        $this->assertDatabaseCount('exam_entries', 2);
        $this->assertSame(1, DB::table('exam_entries')->where('status', 'VALID')->count());
        $this->assertNotSame($id, $second['data']['data']['entry']['id']);
    }

    public function test_reentry_before_annulment_sees_original_valid_entry_and_is_rejected(): void
    {
        $id = $this->initialEntry();
        [$first, $second] = $this->workers($this->operation($this->manualPath(), ['room_id' => $this->exam->room_id]), $this->annulOperation($id));
        $this->assertSame(409, $first['status']);
        $this->assertSame('ALREADY_REGISTERED', $first['data']['result']);
        $this->assertSame(200, $second['status']);
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertSame(0, DB::table('exam_entries')->where('status', 'VALID')->count());
    }

    public function test_manual_and_qr_reentries_competing_after_annulment_create_only_one_valid_entry(): void
    {
        $id = $this->initialEntry();
        $qr = $this->signedQr();
        $this->actingAs($this->owner)->postJson($this->annulPath($id), ['confirmed' => true, 'reason' => 'Corregir ingreso.'])->assertOk();
        [$first, $second] = $this->workers(
            $this->operation($this->manualPath(), ['room_id' => $this->exam->room_id]),
            $this->operation($this->entryBase().'/qr', ['room_id' => $this->exam->room_id, 'qr_token' => $qr], $this->collaborator())
        );
        $this->assertSame(201, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('ALREADY_REGISTERED', $second['data']['result']);
        $this->assertDatabaseCount('exam_entries', 2);
        $this->assertSame(1, DB::table('exam_entries')->where('status', 'VALID')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu28_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu28_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/EntryAnnulmentConcurrencyWorker.php'), $ready, $paths[$index], $index === 0 ? '1' : '0'],
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
