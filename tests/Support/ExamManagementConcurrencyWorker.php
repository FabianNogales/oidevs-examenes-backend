<?php

use App\Models\Exam;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermLock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'pgsql' || ! preg_match('/^hu18_test_[a-f0-9]{12}$/', config('database.connections.pgsql.database'))) {
    throw new RuntimeException('Requires an isolated HU18 PostgreSQL database.');
}
[$script, $ready, $payloadPath, $hold] = $argv;
$operation = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
$clock = CarbonImmutable::parse($operation['clock']);
Carbon::setTestNow($clock);
CarbonImmutable::setTestNow($clock);
Auth::guard('web')->setUser(User::findOrFail($operation['user_id']));
$waitFor = function (string $path) {
    $deadline = microtime(true) + 15;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Synchronization timed out.');
        }
        usleep(10000);
    }
};
try {
    if ($hold === '1') {
        DB::beginTransaction();
        if ($operation['lock'] === 'global') {
            app(AcademicTermLock::class)->acquire();
        }
        if (isset($operation['exam_id'])) {
            Exam::whereKey($operation['exam_id'])->lockForUpdate()->firstOrFail();
        }
        file_put_contents($ready, 'ready');
        $waitFor($ready.'.contender');
        usleep(500000);
    } else {
        $waitFor($ready);
        file_put_contents($ready.'.contender', 'started');
    }
    $request = Request::create($operation['path'], $operation['method'], $operation['payload']);
    $request->headers->set('Accept', 'application/json');
    $response = $kernel->handle($request);
    if ($hold === '1') {
        DB::commit();
    }
    $data = json_decode($response->getContent(), true);
    // The assertions need outcomes, never SVG data or signed token contents.
    unset($data['data']['qr_code_base64'], $data['data']['token']);
    echo json_encode(['status' => $response->getStatusCode(), 'data' => $data], JSON_THROW_ON_ERROR);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
