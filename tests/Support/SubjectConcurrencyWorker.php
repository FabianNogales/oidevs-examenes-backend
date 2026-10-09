<?php

use App\Models\Subject;
use App\Models\User;
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
if (config('database.default') !== 'pgsql' || ! preg_match('/^hu23_test_[a-f0-9]{12}$/', config('database.connections.pgsql.database'))) {
    throw new RuntimeException('Requires an isolated HU23 PostgreSQL database.');
}
[$script, $mode, $userId, $readyPath, $payloadPath, $hold] = $argv;
$payload = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
Auth::guard('web')->setUser(User::findOrFail($userId));
try {
    if ($hold === '1') {
        if ($mode === 'import') {
            DB::beginTransaction();
            DB::table('subject_imports')->where('preview_id', $payload['preview_id'])->lockForUpdate()->first();
            file_put_contents($readyPath, 'ready');
            usleep(700000);
        } else {
            // Force the other process to insert after validation but before this insert.
            Subject::creating(function () use ($readyPath) {
                file_put_contents($readyPath, 'ready');
                usleep(700000);
            });
        }
    } else {
        $deadline = microtime(true) + 15;
        while (! is_file($readyPath)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker synchronization timed out.');
            }
            usleep(10000);
        }
    }
    if (str_starts_with($mode, 'import')) {
        $file = new UploadedFile($payload['_file'], 'subjects.csv', 'text/csv', null, true);
        unset($payload['_file']);
        $request = Request::create('/api/v1/admin/subjects/import/confirm', 'POST', $payload, [], ['file' => $file]);
    } else {
        $request = Request::create('/api/v1/admin/subjects', 'POST', $payload);
    }
    $request->headers->set('Accept', 'application/json');
    $response = $kernel->handle($request);
    if (DB::transactionLevel() > 0) {
        DB::commit();
    }
    echo json_encode(['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
