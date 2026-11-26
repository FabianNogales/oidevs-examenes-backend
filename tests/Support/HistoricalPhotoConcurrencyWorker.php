<?php

use App\Models\Student;
use App\Models\User;
use App\Services\Students\HistoricalPhotoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\RasterImageFixture;

require __DIR__.'/historical-photo-pg-bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $studentId, $ready, $index, $nonce] = $argv;
$wait = static function (callable $condition): void {
    $deadline = microtime(true) + 15;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Historical photo concurrency barrier timed out.');
        }
        usleep(10000);
    }
};
DB::select('SELECT set_config(?, ?, false)', ['application_name', 'eida_historical_'.$nonce.'_'.$index]);
DB::statement("SET lock_timeout = '15s'");
$actor = User::findOrFail(Student::findOrFail($studentId)->user_id)->load('student');
$request = Request::create('/api/v1/students/initial-setup/historical-photo', 'POST', [], [],
    ['photo' => RasterImageFixture::image('initial.png', 2 + (int) $index, 2)]);
$app->instance('request', $request);
// Laravel's request rebind installs its own resolver; apply the synthetic actor afterwards.
$request->setUserResolver(fn () => $actor);

try {
    if ($index === '0') {
        DB::beginTransaction();
        Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
        app(HistoricalPhotoService::class)->register($request);
        file_put_contents($ready, 'registered but not committed');
        $wait(fn () => DB::selectOne('SELECT pid FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = ?',
            ['eida_historical_'.$nonce.'_1', 'Lock']) !== null);
        DB::commit();
        echo json_encode(['status' => 201, 'observed_lock_wait' => true], JSON_THROW_ON_ERROR);
    } else {
        $wait(fn () => is_file($ready));
        try {
            app(HistoricalPhotoService::class)->register($request);
            echo json_encode(['status' => 201], JSON_THROW_ON_ERROR);
        } catch (HttpResponseException $error) {
            echo json_encode(['status' => $error->getResponse()->getStatusCode(),
                'code' => json_decode($error->getResponse()->getContent(), true)['code']], JSON_THROW_ON_ERROR);
        }
    }
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
