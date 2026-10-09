<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Separate PHP process for PostgreSQL integration tests; never targets a work database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'pgsql' || ! preg_match('/^hu17_test_[a-f0-9]{12}$/', config('database.connections.pgsql.database'))) {
    throw new RuntimeException('Concurrency worker requires an isolated HU17 PostgreSQL database.');
}
[$script, $mode, $userId, $target, $readyPath, $payloadPath, $hold] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
$connection = DB::connection();
$user = User::findOrFail($userId);
Auth::guard('web')->setUser($user);
try {
    if ($hold === '1') {
        $connection->beginTransaction();
        $table = $mode === 'reserve' ? 'rooms' : 'room_imports';
        $field = $mode === 'reserve' ? 'id' : 'preview_id';
        $connection->table($table)->where($field, $target)->lockForUpdate()->first();
        file_put_contents($readyPath, 'ready');
        usleep(700000);
    } else {
        $deadline = microtime(true) + 10;
        while (! is_file($readyPath)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker synchronization timed out.');
            }
            usleep(10000);
        }
    }
    if ($mode === 'reserve') {
        $offering = $payload['_offering'];
        unset($payload['_offering']);
        $request = Request::create('/api/v1/course-offerings/'.$offering.'/exams', 'POST', $payload);
    } else {
        $filePath = $payload['_file'];
        unset($payload['_file']);
        $file = new UploadedFile($filePath, 'rooms.csv', 'text/csv', null, true);
        $request = Request::create('/api/v1/admin/rooms/import/confirm', 'POST', $payload, [], ['file' => $file]);
    }
    $request->headers->set('Accept', 'application/json');
    $response = $kernel->handle($request);
    if ($hold === '1') {
        $connection->commit();
    }
    echo json_encode(['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
} finally {
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
}
