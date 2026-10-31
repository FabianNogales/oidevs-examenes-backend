<?php

use App\Models\Exam;
use App\Models\User;
use App\Services\Entries\QrEntryService;
use App\Services\Offline\OfflineSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('EIDA_OFFLINE_PG_CONCURRENCY') !== '1' || ! app()->environment('testing')
    || app()->configurationIsCached() || config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')
    || ! preg_match('/^hu35_37_test_[a-f0-9]{12}$/D', (string) config('database.connections.pgsql.database'))) {
    throw new RuntimeException('Requires explicit authorization and an isolated offline PostgreSQL test database.');
}
[$script, $path, $ready, $index, $nonce] = $argv;
$operation = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$clock = CarbonImmutable::parse($operation['clock']);
Carbon::setTestNow($clock);
CarbonImmutable::setTestNow($clock);
$waitUntil = function (callable $ready): void {
    $deadline = microtime(true) + 15;
    while (! $ready()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency synchronization timed out.');
        }
        usleep(10000);
    }
};
DB::select('SELECT set_config(?, ?, false)', ['application_name', 'eida_offline_'.$nonce.'_'.$index]);
DB::statement("SET lock_timeout = '15s'");
try {
    if ($index === '0') {
        DB::beginTransaction();
        Exam::whereKey($operation['exam_id'])->lockForUpdate()->firstOrFail();
        file_put_contents($ready, 'locked');
        $waitUntil(fn () => is_file($ready.'.contender'));
        $waitUntil(fn () => DB::selectOne('SELECT pid FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = ?',
            ['eida_offline_'.$nonce.'_1', 'Lock']) !== null);
    } else {
        $waitUntil(fn () => is_file($ready));
        file_put_contents($ready.'.contender', 'started');
    }
    $actor = User::findOrFail($operation['actor_id']);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $actor);
    $app->instance('request', $request);
    $result = $operation['mode'] === 'online'
        ? app(QrEntryService::class)->processManual($operation['exam_id'], $operation['payload']['student_id'], $actor, $operation['payload'])->getData(true)
        : app(OfflineSyncService::class)->sync($operation['exam_id'], $operation['payload'], $request);
    if ($index === '0') {
        DB::commit();
    }
    echo json_encode(['result' => $result, 'observed_lock_wait' => $index === '0'], JSON_THROW_ON_ERROR);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
