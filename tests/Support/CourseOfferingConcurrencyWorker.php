<?php

use App\Models\User;
use App\Services\AcademicTerms\AcademicTermLock;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'pgsql' || ! preg_match('/^hu19_test_[a-f0-9]{12}$/', config('database.connections.pgsql.database'))) {
    throw new RuntimeException('Requires an isolated HU19 PostgreSQL database.');
}
[$script, $ready, $payloadPath, $hold] = $argv;
$operation = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
Auth::guard('web')->setUser(User::findOrFail($operation['user_id']));
try {
    if ($hold === '1') {
        DB::beginTransaction();
        app(AcademicTermLock::class)->acquire();
        file_put_contents($ready, 'ready');
        usleep(700000);
    } else {
        $deadline = microtime(true) + 15;
        while (! is_file($ready)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Synchronization timed out.');
            }
            usleep(10000);
        }
    }
    $files = isset($operation['file']) ? ['file' => new UploadedFile($operation['file'], 'offerings.csv', 'text/csv', null, true)] : [];
    $request = Request::create($operation['path'], $operation['method'], $operation['payload'], [], $files);
    $request->headers->set('Accept', 'application/json');
    $response = $kernel->handle($request);
    if ($hold === '1') {
        DB::commit();
    }
    echo json_encode(['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
