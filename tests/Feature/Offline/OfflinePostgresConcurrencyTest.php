<?php

namespace Tests\Feature\Offline;

use App\Services\Offline\OfflineAssignmentService;
use App\Services\Offline\OfflinePackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

class OfflinePostgresConcurrencyTest extends TestCase
{
    use OfflineExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        // Never connect unless the operator explicitly enables a separately provisioned test database.
        if (getenv('EIDA_OFFLINE_PG_CONCURRENCY') !== '1' || ! app()->environment('testing')
            || app()->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.url')
            || ! preg_match('/^hu35_37_test_[a-f0-9]{12}$/D', (string) config('database.connections.pgsql.database'))) {
            $this->markTestSkipped('Requires explicit authorization and an isolated empty hu35_37_test_<12 hex> PostgreSQL database.');
        }
        if (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'") !== []) {
            $this->markTestSkipped('Use a newly provisioned empty test database. Existing tables will not be removed.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $this->createOfflineFixture();
    }

    public function test_concurrent_events_replays_and_online_offline_entries(): void
    {
        $second = $this->addOfflineStudent('Segundo');
        $third = $this->addOfflineStudent('Tercero');
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->fixture['user']);
        app(OfflineAssignmentService::class)->prepare($this->exam->id, $request);
        $package = app(OfflinePackageService::class)->download($this->exam->id, $request);

        [$a, $b] = $this->race($this->offlineBatch($package, [$this->offlineEvent()]), $this->offlineBatch($package, [$this->offlineEvent()]));
        $this->assertSame('ACCEPTED', $a['result']['results'][0]['status']);
        $this->assertSame('DUPLICATE', $b['result']['results'][0]['status']);
        $batch = $this->offlineBatch($package, [$this->offlineEvent($second->id)]);
        [$a, $b] = $this->race($batch, $batch);
        $this->assertSame($a['result'], $b['result']);
        $this->assertSame('ACCEPTED', $a['result']['results'][0]['status']);

        [$a, $b] = $this->race(['student_id' => $third->id, 'room_id' => $package['room_id']],
            $this->offlineBatch($package, [$this->offlineEvent($third->id)]), 'online');
        $this->assertSame('AUTHORIZED', $a['result']['result']);
        $this->assertSame('DUPLICATE', $b['result']['results'][0]['status']);
        $this->assertSame(3, DB::table('exam_entries')->where('status', 'VALID')->count());
        $this->assertSame(4, DB::table('exam_offline_sync_events')->count());
        $this->assertSame(0, DB::table('exam_entries')->select('student_id')->where('status', 'VALID')
            ->groupBy('exam_id', 'student_id')->havingRaw('COUNT(*) > 1')->get()->count());
    }

    private function race(array $first, array $second, string $firstMode = 'offline'): array
    {
        $paths = [];
        $processes = [];
        $ready = tempnam(sys_get_temp_dir(), 'eida_offline_ready_');
        unlink($ready);
        $nonce = bin2hex(random_bytes(6));
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_TIMEZONE' => config('app.timezone'),
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'EIDA_OFFLINE_PG_CONCURRENCY' => '1'];
        foreach (['host', 'port', 'database', 'username', 'password', 'sslmode'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.pgsql.'.$key);
        }
        try {
            foreach ([$first, $second] as $index => $payload) {
                $path = tempnam(sys_get_temp_dir(), 'eida_offline_payload_');
                $paths[] = $path;
                file_put_contents($path, json_encode(['exam_id' => $this->exam->id, 'actor_id' => $this->fixture['user']->id,
                    'clock' => now()->toISOString(), 'payload' => $payload, 'mode' => $index === 0 ? $firstMode : 'offline'], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/OfflineConcurrencyWorker.php'), $path, $ready, (string) $index, $nonce],
                    base_path(), $environment, timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            // The first process observed the contender waiting for a PostgreSQL lock, not just sequential calls.
            $this->assertTrue($results[0]['observed_lock_wait']);

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($paths, [$ready, $ready.'.contender']) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
