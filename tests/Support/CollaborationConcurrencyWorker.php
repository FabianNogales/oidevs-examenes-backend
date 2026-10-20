<?php

use App\Models\Exam;
use App\Models\StudentQrToken;
use App\Models\User;
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
if (getenv('EIDA_FIX010_PG_CONCURRENCY') !== '1' || ! $app->environment('testing')
    || DB::getDriverName() !== 'pgsql'
    || ! preg_match('/^fix010_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())
    || config('database.connections.pgsql.url') !== null || $app->configurationIsCached()
    || config('cache.default') !== 'array' || config('session.driver') !== 'array'
    || realpath(storage_path()) === realpath(base_path('storage'))) {
    throw new RuntimeException('Requires explicitly isolated FIX-010 PostgreSQL environment.');
}
$pdo = DB::connection()->getPdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
    || $pdo->query('SELECT current_database()')->fetchColumn() !== DB::connection()->getDatabaseName()) {
    throw new RuntimeException('Actual PDO database must match the isolated test configuration.');
}
[$script, $prefix, $payloadPath, $index] = $argv;
$data = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
Carbon::setTestNow(CarbonImmutable::parse($data['clock']));
CarbonImmutable::setTestNow(CarbonImmutable::parse($data['clock']));
$wait = function (string $path): void {
    $deadline = microtime(true) + 12;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('FIX-010 synchronization timed out.');
        }
        usleep(10000);
    }
};
try {
    if ($index === '0') {
        DB::beginTransaction();
        Exam::whereKey($data['exam_id'])->lockForUpdate()->firstOrFail();
        file_put_contents($prefix.'.ready', 'ready');
        $wait($prefix.($data['independent'] ? '.done' : '.waiting'));
    } else {
        $wait($prefix.'.ready');
        DB::connection()->beforeExecuting(function ($query) use ($prefix): void {
            if (str_contains($query, 'from "exams"') && str_contains($query, 'for update')) {
                // For DELETE, its nonlocking period snapshot has already been captured.
                file_put_contents($prefix.'.waiting', 'waiting for exam lock');
            }
        });
    }
    $statuses = [];
    foreach ($data['operations'] as $operation) {
        if (isset($operation['deactivate_user'])) {
            User::whereKey($operation['deactivate_user'])->update(['status' => 'INACTIVE']);
            $statuses[] = 200;

            continue;
        }
        $payload = $operation['payload'];
        if (isset($operation['qr_student_id'])) {
            $record = StudentQrToken::where('exam_id', $operation['qr_exam_id'])
                ->where('student_id', $operation['qr_student_id'])->firstOrFail();
            $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
            $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'
                .$encode(['v' => 2, 'exp' => now()->addHour()->timestamp, 'jti' => $record->token]);
            $payload['qr_token'] = $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
        }
        Auth::guard('web')->setUser(User::findOrFail($operation['actor']));
        $request = Request::create($operation['path'], $operation['method'], $payload);
        $request->headers->set('Accept', 'application/json');
        $statuses[] = $kernel->handle($request)->getStatusCode();
    }
    if ($index === '0') {
        DB::commit();
    } else {
        file_put_contents($prefix.'.done', 'done');
    }
    // Never print response identities, QR credentials or database diagnostics.
    echo json_encode(['status' => $statuses[0], 'statuses' => $statuses], JSON_THROW_ON_ERROR);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
