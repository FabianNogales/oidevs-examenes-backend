<?php

namespace Tests\Integration\Students;

use App\Models\MobileDevice;
use App\Models\Role;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\MobileTokenPermissions;
use App\Services\Images\DatabaseImageService;
use App\Services\Offline\MobileOfflinePackageService;
use App\Services\Offline\OfflineAssignmentService;
use App\Services\Offline\OfflineSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\OfflineExamFixture;
use Tests\Support\RasterImageFixture;
use Tests\TestCase;

class HistoricalPhotoPostgresTest extends TestCase
{
    use OfflineExamFixture;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EIDA_HISTORICAL_PHOTO_PG') !== '1') {
            $this->markTestSkipped('Opt in only with the isolated historical-photo PostgreSQL bootstrap.');
        }
        if (! app()->environment('testing') || app()->configurationIsCached() || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.url') || config('database.connections.pgsql.host') !== '127.0.0.1'
            || (string) config('database.connections.pgsql.port') !== '55447'
            || config('database.connections.pgsql.username') !== 'eida_hist_test'
            || ! preg_match('/\Aeida_hist_test_[a-f0-9]{12}\z/', (string) config('database.connections.pgsql.database'))) {
            throw new \RuntimeException('Refusing to migrate any database outside the disposable test cluster.');
        }
        $this->assertSame(15, intdiv((int) DB::selectOne('SHOW server_version_num')->server_version_num, 10000));
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['mobile_offline.qr_v3_issued' => false]);
        $this->createOfflineFixture();
        $this->student = Student::findOrFail($this->fixture['studentId']);
        $role = Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        $this->student->user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
    }

    private function register(): void
    {
        $this->actingAs($this->student->user()->first())->postJson('/api/v1/students/initial-setup/historical-photo',
            ['photo' => RasterImageFixture::image()])->assertCreated();
        $this->student->refresh();
    }

    public function test_additive_migration_preserves_accounts_academic_relations_and_bytea_images(): void
    {
        $this->register();
        $migration = require database_path('migrations/2026_11_25_000001_add_historical_photo_source_to_students.php');
        $migration->down();
        $student = (array) DB::table('students')->where('id', $this->student->id)->first();
        $account = (array) DB::table('users')->where('id', $this->student->user_id)->first();
        $enrollments = DB::table('enrollments')->get()->toJson();
        $images = DB::select("SELECT id, purpose, student_id, sha256, octet_length(content) AS size, encode(content, 'hex') AS hex FROM stored_images ORDER BY id");
        $migration->up();
        $this->assertTrue(Schema::hasColumn('students', 'historical_photo_source'));
        $column = DB::selectOne("SELECT data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_schema='public' AND table_name='students' AND column_name='historical_photo_source'");
        $this->assertSame('character varying', $column->data_type);
        $this->assertSame(32, $column->character_maximum_length);
        $this->assertSame('YES', $column->is_nullable);
        $after = (array) DB::table('students')->where('id', $this->student->id)->first();
        $this->assertNull($after['historical_photo_source']);
        unset($after['historical_photo_source']);
        $this->assertSame($student, $after);
        $this->assertSame($account, (array) DB::table('users')->where('id', $this->student->user_id)->first());
        $this->assertSame($enrollments, DB::table('enrollments')->get()->toJson());
        $this->assertEquals($images, DB::select("SELECT id, purpose, student_id, sha256, octet_length(content) AS size, encode(content, 'hex') AS hex FROM stored_images ORDER BY id"));
        $this->assertCount(2, $images);
        foreach ($images as $image) {
            $bytes = hex2bin($image->hex);
            $this->assertSame(strlen($bytes), $image->size);
            $this->assertSame(hash('sha256', $bytes), $image->sha256);
            $this->assertSame($bytes, app(DatabaseImageService::class)->read($image->id, $image->purpose, $this->student->id)['bytes']);
        }
    }

    public function test_simultaneous_initial_registration_has_one_winner_and_no_orphan_images(): void
    {
        $ready = tempnam(sys_get_temp_dir(), 'eida_historical_');
        unlink($ready);
        $nonce = bin2hex(random_bytes(6));
        $processes = [];
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'APP_KEY' => config('app.key'), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'EIDA_HISTORICAL_PHOTO_PG' => '1'];
        foreach (['host', 'port', 'database', 'username', 'password', 'sslmode'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.pgsql.'.$key);
        }
        try {
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/HistoricalPhotoConcurrencyWorker.php'),
                    (string) $this->student->id, $ready, (string) $index, $nonce], base_path(), $environment, timeout: 40);
                $process->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertJson($process->getOutput(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(['status' => 201, 'observed_lock_wait' => true], $results[0]);
            $this->assertSame(['status' => 409, 'code' => 'HISTORICAL_PHOTO_EXISTS'], $results[1]);
            $this->student->refresh()->load('user');
            $this->assertSame(1, $this->student->historical_photo_revision);
            $this->assertSame('STUDENT_UPLOAD', $this->student->historical_photo_source);
            $this->assertDatabaseCount('stored_images', 2);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'STUDENT_HISTORICAL_PHOTO_REGISTERED')->count());
            $this->assertSame(0, DB::table('stored_images as i')->leftJoin('students as s', 's.historical_image_id', '=', 'i.id')
                ->leftJoin('users as u', 'u.profile_image_id', '=', 'i.id')->whereNull('s.id')->whereNull('u.id')->count());
            $image = app(DatabaseImageService::class)->read($this->student->historical_image_id, 'historical', $this->student->id);
            $this->assertSame(2, getimagesizefromstring($image['bytes'])[0]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if (is_file($ready)) {
                unlink($ready);
            }
        }
    }

    public function test_failed_registration_rolls_back_both_image_purposes_on_postgresql(): void
    {
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->actingAs($this->student->user)->postJson('/api/v1/students/initial-setup/historical-photo',
            ['photo' => RasterImageFixture::image()])->assertStatus(500);
        $this->assertNull($this->student->fresh()->historical_image_id);
        $this->assertNull($this->student->user()->first()->profile_image_id);
        $this->assertDatabaseCount('stored_images', 0);
    }

    public function test_optional_csv_columns_preview_confirmation_and_empty_reimports_preserve_photos(): void
    {
        $this->actingAs($this->fixture['user']);
        foreach (['', ',profile_photo', ',historical_photo', ',profile_photo,historical_photo'] as $index => $suffix) {
            $csv = 'sis_code,identity_number,first_names,last_names,email,career'.$suffix."\n"
                .'2026110'.$index.',765432'.$index.',Luis,Prueba,csv'.$index.'@umss.edu.bo,Sistemas'.str_repeat(',', substr_count($suffix, ','));
            foreach (['preview', 'confirm'] as $operation) {
                $this->postJson('/api/v1/admin/students/import/'.$operation, ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv)])
                    ->assertOk()->assertJsonPath('data.valid_rows', 1)->assertJsonPath('data.error_rows', 0);
            }
            $this->assertNull(Student::where('sis_code', '2026110'.$index)->firstOrFail()->historical_image_id);
        }
        $this->register();
        $before = $this->student->fresh('user');
        $csv = "sis_code,identity_number,first_names,last_names,email,career,profile_photo,historical_photo\n"
            .$before->sis_code.','.$before->identity_number.',Alumno,Prueba,'.$before->user->email.',Sistemas,,';
        $this->actingAs($this->fixture['user'])->postJson('/api/v1/admin/students/import/preview', ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv)])
            ->assertOk()->assertJsonPath('data.valid_rows', 0);
        $this->postJson('/api/v1/admin/students/import/confirm', ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv)])
            ->assertOk()->assertJsonPath('data.imported_rows', 0)->assertJsonPath('data.skipped_rows', 1);
        $after = $this->student->fresh('user');
        $this->assertSame($before->historical_image_id, $after->historical_image_id);
        $this->assertSame($before->historical_photo_revision, $after->historical_photo_revision);
        $this->assertSame($before->user->profile_image_id, $after->user->profile_image_id);
        $this->assertSame($before->user->password, $after->user->password);
        $this->assertDatabaseCount('stored_images', 2);
    }

    public function test_packages_before_registration_and_correction_keep_snapshots_and_pending_event_sync(): void
    {
        $actor = $this->fixture['user'];
        $device = MobileDevice::create(['user_id' => $actor->id, 'installation_id' => (string) Str::uuid(),
            'name' => 'Isolated historical-photo test', 'platform' => 'android', 'last_login_at' => now()]);
        $token = $actor->createToken('eida-control:historical-test', MobileTokenPermissions::ABILITIES, now()->addHours(2));
        $token->accessToken->forceFill(['mobile_device_id' => $device->id])->save();
        $actor->withAccessToken($token->accessToken);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);
        app(OfflineAssignmentService::class)->prepare($this->exam->id, $request);
        $packages = app(MobileOfflinePackageService::class);
        $before = $packages->download($this->exam->id, $request);
        $manifest = json_decode($before['mobile']['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['ABSENT', 'ABSENT'], array_column($manifest['students'][0]['photos'], 'state'));
        $pending = $this->offlineEvent(null, ['device_id' => $device->installation_id,
            'time_evidence' => ['serverMs' => now()->timestamp * 1000, 'boot' => '3', 'elapsed' => 100000,
                'capturedElapsed' => 100000, 'source' => 'SERVER_ANCHORED_ESTIMATE']]);
        $this->register();
        $withPhoto = $packages->download($this->exam->id, $request);
        $photoManifest = json_decode($withPhoto['mobile']['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
        $photo = collect($photoManifest['students'][0]['photos'])->firstWhere('kind', 'historical');
        $this->assertSame('PRESENT', $photo['state']);
        $oldBytes = $packages->photo($this->exam->id, $withPhoto['package_id'], $this->student->id, 'historical', $request)->getCallback();
        ob_start();
        $oldBytes();
        $originalBytes = ob_get_clean();
        $path = '/api/v1/admin/students/'.$this->student->id.'/historical-photo/corrections';
        $this->actingAs($actor)->postJson($path, ['photo' => RasterImageFixture::image('correction.png', 3, 2),
            'revision' => 1, 'reason' => 'Synthetic correction'])->assertOk();
        foreach ([$before, $withPhoto] as $package) {
            $resumed = $packages->resume($this->exam->id, $package['package_id'], $request)['mobile'];
            $this->assertEquals($package['mobile'], $resumed);
            $this->assertSame($package['mobile']['manifest_json'], $resumed['manifest_json']);
            $this->assertSame($package['mobile']['manifest_sha256'], $resumed['manifest_sha256']);
        }
        $response = $packages->photo($this->exam->id, $withPhoto['package_id'], $this->student->id, 'historical', $request);
        ob_start();
        ($response->getCallback())();
        $this->assertSame($originalBytes, ob_get_clean());
        try {
            $packages->photo($this->exam->id, $before['package_id'], $this->student->id, 'historical', $request);
            $this->fail('An ABSENT historical photo must remain absent in its original package.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $latest = $packages->download($this->exam->id, $request);
        $latestManifest = json_decode($latest['mobile']['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
        $latestPhoto = collect($latestManifest['students'][0]['photos'])->firstWhere('kind', 'historical');
        $this->assertNotSame($photo['sha256'], $latestPhoto['sha256']);
        $batch = ['protocol_version' => 2] + $this->offlineBatch($before, [$pending]);
        $result = app(OfflineSyncService::class)->sync($this->exam->id, $batch, $request);
        $this->assertSame('ACCEPTED', $result['results'][0]['status']);
        $this->assertSame($result, app(OfflineSyncService::class)->sync($this->exam->id, $batch, $request));
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertDatabaseCount('exam_offline_sync_events', 1);
        $this->assertDatabaseCount('exam_offline_packages', 3);
    }
}
