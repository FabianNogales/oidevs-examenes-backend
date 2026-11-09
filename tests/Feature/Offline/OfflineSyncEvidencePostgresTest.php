<?php

namespace Tests\Feature\Offline;

use App\Services\Offline\OfflineAssignmentService;
use App\Services\Offline\OfflinePackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

/** Written only. Run separately against a newly provisioned empty test database, never production. */
class OfflineSyncEvidencePostgresTest extends TestCase
{
    use OfflineExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EIDA_OFFLINE_PG_CONCURRENCY') !== '1' || ! app()->environment('testing') || app()->configurationIsCached()
            || config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')
            || ! preg_match('/^hu35_37_test_[a-f0-9]{12}$/D', (string) config('database.connections.pgsql.database'))) {
            $this->markTestSkipped('Explicit opt-in and isolated hu35_37_test_<12 hex> PostgreSQL database required.');
        }
        if (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public'") !== []) {
            $this->markTestSkipped('Requires a new EMPTY database. Existing tables will not be dropped.');
        }
        Artisan::call('migrate', ['--force' => true]);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        $this->createOfflineFixture();
    }

    public function test_concurrent_v2_live_and_late_replays_keep_single_result_evidence_and_audit(): void
    {
        $second = $this->addOfflineStudent('Segundo');
        $request = Request::create('/'); $request->setUserResolver(fn () => $this->fixture['user']);
        app(OfflineAssignmentService::class)->prepare($this->exam->id, $request);
        $package = app(OfflinePackageService::class)->download($this->exam->id, $request);
        $event = $this->event(); $batch = ['protocol_version' => 2] + $this->offlineBatch($package, [$event]);
        [$a, $b] = $this->race($batch, $batch);
        $this->assertSame($a['results'], $b['results']); $this->assertSame('ACCEPTED', $a['results'][0]['status']);
        $late = $this->event($second->id); $lateBatch = ['protocol_version' => 2] + $this->offlineBatch($package, [$late]);
        $this->travelTo(now()->addHours(2));
        [$a, $b] = $this->race($lateBatch, $lateBatch);
        $this->assertSame($a, $b); $this->assertSame('EXAM_ENDED', $a['results'][0]['code']);
        $this->assertTrue($a['results'][0]['review_required']);
        $stored = DB::table('exam_offline_sync_events')->where('event_id', $late['event_id'])->first();
        $this->assertSame($late, json_decode(Crypt::decryptString($stored->evidence_ciphertext), true)['event']);
        $changed = $lateBatch; $changed['events'][0]['time_evidence']['elapsed']++;
        [$different, $original] = $this->race($changed, $lateBatch);
        $this->assertSame('EVENT_ID_REUSED', $different['results'][0]['code']);
        $this->assertSame($a, $original);
        $this->assertSame(1, DB::table('exam_entries')->count());
        $this->assertSame(2, DB::table('exam_offline_sync_events')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'OFFLINE_EVENT_PROCESSED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'OFFLINE_EVIDENCE_REVIEW_REQUIRED')->count());
    }

    private function event(?int $student = null): array
    {
        return $this->offlineEvent($student, ['time_evidence' => ['serverMs' => now()->timestamp * 1000,
            'boot' => '3', 'elapsed' => 100000, 'capturedElapsed' => 100000, 'source' => 'SERVER_ANCHORED_ESTIMATE']]);
    }

    private function race(array $first, array $second): array
    {
        // Reuses the existing real-PostgreSQL worker and its observed row-lock barrier.
        $paths = []; $processes = []; $ready = tempnam(sys_get_temp_dir(), 'eida_sync_ready_'); unlink($ready);
        $nonce = bin2hex(random_bytes(6));
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_TIMEZONE' => config('app.timezone'),
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'EIDA_OFFLINE_PG_CONCURRENCY' => '1'];
        foreach (['host', 'port', 'database', 'username', 'password', 'sslmode'] as $key) $environment['DB_'.strtoupper($key)] = (string) config('database.connections.pgsql.'.$key);
        try {
            foreach ([$first, $second] as $i => $payload) {
                $path = tempnam(sys_get_temp_dir(), 'eida_sync_payload_'); $paths[] = $path;
                file_put_contents($path, json_encode(['exam_id' => $this->exam->id, 'actor_id' => $this->fixture['user']->id,
                    'clock' => now()->toISOString(), 'payload' => $payload, 'mode' => 'offline'], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/OfflineConcurrencyWorker.php'), $path, $ready, (string) $i, $nonce], base_path(), $environment, timeout: 30);
                $process->start(); $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait(); $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                if ($results === []) $this->assertTrue($decoded['observed_lock_wait']);
                $results[] = $decoded['result'];
            }
            return $results;
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (array_merge($paths, [$ready, $ready.'.contender']) as $path) if (is_file($path)) unlink($path);
        }
    }
}
