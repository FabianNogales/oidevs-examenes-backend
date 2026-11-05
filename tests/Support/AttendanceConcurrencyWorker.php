<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (DB::getDriverName() !== 'pgsql' || ! preg_match('/^hu32_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
    throw new RuntimeException('Requires an isolated HU32 PostgreSQL database.');
}
[$script, $ready, $payloadPath, $mode] = $argv;
$operation = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
$clock = CarbonImmutable::parse($operation['clock']);
Carbon::setTestNow($clock);
CarbonImmutable::setTestNow($clock);
Auth::guard('web')->setUser(User::findOrFail($operation['user_id']));
$waitFor = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Snapshot synchronization timed out.');
        }
        usleep(10000);
    }
};
try {
    if ($mode === 'reader') {
        $paused = false;
        DB::listen(function (QueryExecuted $query) use ($ready, $waitFor, &$paused) {
            if (! $paused && str_contains($query->sql, 'COUNT(*) AS total, COUNT(entry.id) AS registered')) {
                $paused = true;
                file_put_contents($ready, 'snapshot established');
                $waitFor($ready.'.writer_done');
            }
        });
    } else {
        $waitFor($ready);
    }
    if (($operation['direct'] ?? null) === 'deactivate_enrollment') {
        DB::table('enrollments')->where('course_offering_id', $operation['offering_id'])
            ->where('student_id', $operation['student_id'])->update(['status' => 'INACTIVE']);
        $result = ['status' => 200];
    } else {
        $request = Request::create($operation['path'], $operation['method'], $operation['payload'] ?? []);
        $request->headers->set('Accept', 'application/json');
        $response = $kernel->handle($request);
        $result = ['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if ($mode === 'writer') {
        file_put_contents($ready.'.writer_done', 'committed');
    }
}
