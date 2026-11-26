<?php

namespace Tests\Feature\Students;

use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Images\DatabaseImageService;
use App\Services\Students\HistoricalPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RasterImageFixture;
use Tests\TestCase;

class StudentInitialSetupTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private const UPLOAD = '/api/v1/students/initial-setup/historical-photo';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $user = $this->actor('ESTUDIANTE');
        $career = Career::create(['code' => 'SETUP', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $this->student = Student::create(['user_id' => $user->id, 'career_id' => $career->id,
            'sis_code' => '20260001', 'identity_number' => '1234567', 'first_names' => 'Ana',
            'last_names' => 'Prueba', 'status' => 'ACTIVE']);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false, 'password' => 'Password1!']);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    private function loginStudent(): static
    {
        return $this->actingAs($this->student->user()->firstOrFail());
    }

    private function upload(): void
    {
        $this->loginStudent()->postJson(self::UPLOAD, ['photo' => RasterImageFixture::image()])->assertCreated();
    }

    public function test_existing_student_cannot_bypass_setup_using_private_api_routes(): void
    {
        $this->loginStudent()->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.must_change_password', false)->assertJsonPath('data.must_upload_historical_photo', true);
        foreach (['/students/profile', '/students/exams', '/students/exams/1/qr', '/me/collaborations', '/auth/devices'] as $path) {
            $this->getJson('/api/v1'.$path)->assertForbidden()->assertJsonPath('code', 'HISTORICAL_PHOTO_REQUIRED');
        }
        $this->postJson('/api/v1/students/profile/photo', ['photo' => RasterImageFixture::image()])
            ->assertForbidden()->assertJsonPath('code', 'HISTORICAL_PHOTO_REQUIRED');
        $this->postJson('/api/v1/auth/logout')->assertSuccessful();
    }

    public function test_password_is_required_first_and_photo_remains_pending_after_password_change(): void
    {
        $this->student->user->forceFill(['must_change_password' => true])->save();
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.must_upload_historical_photo', true);
        $this->postJson(self::UPLOAD, ['photo' => RasterImageFixture::image()])->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->putJson('/user/password', ['current_password' => 'Password1!', 'password' => 'UpdatedPassword2!',
            'password_confirmation' => 'UpdatedPassword2!'])->assertSuccessful();
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.must_upload_historical_photo', true);
        $this->getJson('/api/v1/students/profile')->assertForbidden();
        $this->upload();
        $this->loginStudent()->getJson('/api/v1/students/profile')->assertOk();
    }

    public function test_registration_persists_owned_images_and_unverified_student_origin_atomically(): void
    {
        $this->upload();
        $student = $this->student->fresh('user');
        $this->assertSame('STUDENT_UPLOAD', $student->historical_photo_source);
        $this->assertSame(1, $student->historical_photo_revision);
        $this->assertNotSame($student->historical_image_id, $student->user->profile_image_id);
        $images = app(DatabaseImageService::class);
        $historical = $images->read($student->historical_image_id, 'historical', $student->id);
        $this->assertSame($historical['bytes'], $images->read($student->user->profile_image_id, 'profile', $student->id)['bytes']);
        $this->assertDatabaseCount('stored_images', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_HISTORICAL_PHOTO_REGISTERED', 'user_id' => $student->user_id]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_upload_historical_photo', false);
    }

    public function test_student_cannot_replace_or_delete_history_and_profile_updates_are_independent(): void
    {
        $this->upload();
        $historical = $this->student->fresh()->historical_image_id;
        $this->loginStudent()->postJson(self::UPLOAD, ['photo' => RasterImageFixture::image()])->assertForbidden();
        $this->deleteJson(self::UPLOAD)->assertStatus(405);
        $this->postJson('/api/v1/admin/students/'.$this->student->id.'/historical-photo/corrections',
            ['photo' => RasterImageFixture::image(), 'revision' => 1, 'reason' => 'Intento'])->assertForbidden();
        $this->postJson('/api/v1/students/profile/photo', ['photo' => RasterImageFixture::image('other.jpg')])->assertOk();
        $this->assertSame($historical, $this->student->fresh()->historical_image_id);
        $this->assertDatabaseHas('stored_images', ['id' => $historical, 'purpose' => 'historical']);
        $this->assertDatabaseCount('stored_images', 2);
    }

    public function test_existing_profile_is_not_overwritten(): void
    {
        $this->student->user->update(['profile_photo' => 'existing.jpg']);
        $this->upload();
        $this->assertSame('existing.jpg', $this->student->user()->first()->profile_photo);
        $this->assertDatabaseCount('stored_images', 1);
    }

    public function test_invalid_uploads_do_not_leave_images_or_partial_registration(): void
    {
        $this->loginStudent();
        $image = RasterImageFixture::image();
        $bytes = file_get_contents($image->getRealPath());
        foreach ([UploadedFile::fake()->create('photo.png', 20, 'text/plain'),
            UploadedFile::fake()->createWithContent('broken.png', substr($bytes, 0, -5)),
            UploadedFile::fake()->create('large.png', 2049, 'image/png')] as $file) {
            $this->postJson(self::UPLOAD, ['photo' => $file])->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $this->assertNull($this->student->fresh()->historical_image_id);
        $this->assertDatabaseCount('stored_images', 0);
    }

    public function test_audit_failure_rolls_back_both_image_purposes(): void
    {
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->loginStudent()->postJson(self::UPLOAD, ['photo' => RasterImageFixture::image()])->assertStatus(500);
        $this->assertNull($this->student->fresh()->historical_image_id);
        $this->assertNull($this->student->user()->first()->profile_image_id);
        $this->assertDatabaseCount('stored_images', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_valid_institutional_photo_skips_setup_and_admin_correction_remains_audited(): void
    {
        $admin = $this->actor('ADMINISTRADOR');
        $path = '/api/v1/admin/students/'.$this->student->id.'/historical-photo';
        $this->actingAs($admin)->postJson($path, ['photo' => RasterImageFixture::image(), 'revision' => 0])->assertCreated();
        $old = $this->student->fresh()->historical_image_id;
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_upload_historical_photo', false);
        $this->getJson('/api/v1/students/profile')->assertOk();
        $this->actingAs($admin)->postJson($path.'/corrections', ['photo' => RasterImageFixture::image('new.jpg'),
            'revision' => 1, 'reason' => 'Corrección autorizada'])->assertOk()->assertJsonPath('data.historical_photo_source', 'STAFF_UPLOAD');
        $this->assertDatabaseHas('stored_images', ['id' => $old]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_INSTITUTIONAL_PHOTO_CORRECTED']);
    }

    public function test_missing_legacy_file_and_foreign_owned_reference_do_not_complete_setup(): void
    {
        $this->student->forceFill(['historical_photo' => 'institutional-photos/missing.png'])->save();
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_upload_historical_photo', true);
        $id = DB::transaction(fn () => app(DatabaseImageService::class)->store(RasterImageFixture::image(), 'profile', $this->student->id, $this->student->user_id));
        $this->student->forceFill(['historical_image_id' => $id])->save();
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_upload_historical_photo', true);
        $this->upload();
        $this->assertDatabaseHas('stored_images', ['id' => $id, 'purpose' => 'profile']);
    }

    public function test_teachers_administrators_and_staff_do_not_need_student_photos(): void
    {
        foreach (['DOCENTE', 'ADMINISTRADOR', 'Secretaría'] as $role) {
            $actor = $this->actor($role);
            $this->actingAs($actor)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.must_upload_historical_photo', false);
            $this->postJson(self::UPLOAD, ['photo' => RasterImageFixture::image()])->assertForbidden();
        }
        $this->student->user->roles()->attach(Role::where('name', 'DOCENTE')->value('id'), ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->loginStudent()->getJson('/api/v1/me')->assertJsonPath('data.must_upload_historical_photo', false);
    }

    public function test_csv_photos_are_optional_and_existing_records_are_never_erased(): void
    {
        $admin = $this->actor('ADMINISTRADOR');
        foreach (['', ',profile_photo', ',historical_photo', ',profile_photo,historical_photo'] as $i => $suffix) {
            $csv = 'sis_code,identity_number,first_names,last_names,email,career'.$suffix."\n"
                .'2026010'.$i.',765432'.$i.',Luis,Prueba,luis'.$i.'@umss.edu.bo,Sistemas'.str_repeat(',', substr_count($suffix, ','));
            $this->actingAs($admin)->postJson('/api/v1/admin/students/import/confirm', [
                'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
            ])->assertOk()->assertJsonPath('data.imported_rows', 1);
            $student = Student::where('sis_code', '2026010'.$i)->firstOrFail();
            $this->assertTrue($student->user->must_change_password);
            $this->assertTrue(app(HistoricalPhotoService::class)->requiredFor($student->user));
        }
        $this->upload();
        $id = $this->student->fresh()->historical_image_id;
        $csv = "sis_code,identity_number,first_names,last_names,email,career,historical_photo\n20260001,1234567,Ana,Prueba,existing@umss.edu.bo,Sistemas,";
        $this->actingAs($admin)->postJson('/api/v1/admin/students/import/confirm', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ])->assertOk()->assertJsonPath('data.skipped_rows', 1);
        $this->assertSame($id, $this->student->fresh()->historical_image_id);
    }

    public function test_csv_import_stores_valid_historical_bytes_and_reports_invalid_references(): void
    {
        $admin = $this->actor('ADMINISTRADOR');
        $image = RasterImageFixture::image();
        Storage::disk('local')->put('institutional-photos/import.png', file_get_contents($image->getRealPath()));
        $header = "sis_code,identity_number,first_names,last_names,email,career,historical_photo\n";
        $this->actingAs($admin)->postJson('/api/v1/admin/students/import/confirm', ['file' => UploadedFile::fake()->createWithContent('students.csv',
            $header.'20260500,8765432,Luis,Prueba,import@umss.edu.bo,Sistemas,institutional-photos/import.png')])
            ->assertOk()->assertJsonPath('data.imported_rows', 1);
        $student = Student::where('sis_code', '20260500')->firstOrFail();
        $this->assertSame('INSTITUTIONAL_IMPORT', $student->historical_photo_source);
        $this->assertFalse(app(HistoricalPhotoService::class)->requiredFor($student->user));
        $this->assertDatabaseHas('stored_images', ['id' => $student->historical_image_id, 'purpose' => 'historical']);
        foreach (['https://example.com/photo.png', '../private.png', 'institutional-photos/missing.png'] as $path) {
            $this->postJson('/api/v1/admin/students/import/preview', ['file' => UploadedFile::fake()->createWithContent('students.csv',
                $header.'20260501,8765433,Luis,Prueba,invalid@umss.edu.bo,Sistemas,'.$path)])
                ->assertOk()->assertJsonPath('data.error_rows', 1);
        }
    }
}
