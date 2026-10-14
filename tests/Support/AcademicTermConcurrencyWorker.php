<?php

use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermLock;
use App\Services\AcademicTerms\AcademicTermOfferingGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'pgsql' || ! preg_match('/^hu24_test_[a-f0-9]{12}$/', config('database.connections.pgsql.database'))) {
    throw new RuntimeException('Requires an isolated HU24 PostgreSQL database.');
}
[$script, $userId, $readyPath, $payloadPath, $hold] = $argv;
$operation = json_decode(file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
Auth::guard('web')->setUser(User::findOrFail($userId));
try {
    if ($hold === '1') {
        DB::beginTransaction();
        app(AcademicTermLock::class)->acquire();
        file_put_contents($readyPath, 'ready');
        usleep(700000);
    } else {
        $deadline = microtime(true) + 15;
        while (! is_file($readyPath)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Synchronization timed out.');
            }
            usleep(10000);
        }
    }
    if ($operation['mode'] === 'offer') {
        try {
            $offering = app(AcademicTermOfferingGuard::class)->write($operation['payload']['academic_term_id'],
                fn () => CourseOffering::create($operation['payload']));
            $result = ['status' => 201, 'data' => ['id' => $offering->id]];
        } catch (HttpResponseException $exception) {
            $result = ['status' => $exception->getResponse()->getStatusCode(), 'data' => json_decode($exception->getResponse()->getContent(), true)];
        }
    } else {
        $request = Request::create($operation['path'], $operation['method'], $operation['payload']);
        $request->headers->set('Accept', 'application/json');
        $response = $kernel->handle($request);
        $result = ['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)];
    }
    if ($hold === '1') {
        DB::commit();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
