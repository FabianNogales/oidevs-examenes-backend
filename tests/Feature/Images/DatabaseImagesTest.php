<?php

namespace Tests\Feature\Images;

use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RasterImageFixture;
use Tests\TestCase;

class DatabaseImagesTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->actor('ESTUDIANTE');
        $career = Career::create(['code' => 'IMG', 'name' => 'Pruebas imágenes', 'status' => 'ACTIVE']);
        $this->student = Student::create(['user_id' => $user->id, 'career_id' => $career->id, 'sis_code' => '000123',
            'identity_number' => '12345', 'first_names' => 'Ana', 'last_names' => 'Prueba', 'status' => 'ACTIVE']);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    private function upload(): string
    {
        $response = $this->actingAs($this->student->user)->postJson('/api/v1/students/profile/photo', ['photo' => RasterImageFixture::image()])->assertOk();
        $this->student->user->refresh();

        return $response->json('data.profile_photo_url');
    }

    private function bytes(object $record): string
    {
        return is_resource($record->content) ? stream_get_contents($record->content) : $record->content;
    }

    public function test_upload_reads_binary_with_metadata_and_integrity_and_no_disk_write(): void
    {
        $url = $this->upload();
        $record = DB::table('stored_images')->first();
        $bytes = $this->bytes($record);
        $this->assertSame($this->student->id, (int) $record->student_id);
        $this->assertSame($this->student->user_id, (int) $record->uploaded_by);
        $this->assertSame('profile', $record->purpose);
        $this->assertSame('image/png', $record->mime_type);
        $this->assertSame(strlen($bytes), (int) $record->byte_size);
        $this->assertSame(hash('sha256', $bytes), $record->sha256);
        $this->assertNotNull($record->uploaded_at);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Length', (string) strlen($bytes))->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        if (DB::getDriverName() === 'pgsql') {
            $column = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'stored_images' AND column_name = 'content'");
            $this->assertSame('bytea', $column->data_type);
            $raw = DB::selectOne("SELECT octet_length(content) AS size, encode(content, 'hex') AS hex FROM stored_images WHERE id = ?", [$record->id]);
            $this->assertSame(bin2hex($bytes), $raw->hex);
            $this->assertSame(strlen($bytes), (int) $raw->size);
        }
    }

    public function test_replacement_updates_reference_and_audit_and_discards_previous_profile_only(): void
    {
        $oldUrl = $this->upload();
        $oldId = $this->student->user->profile_image_id;
        $newUrl = $this->upload();
        $this->assertNotSame($oldUrl, $newUrl);
        $this->assertDatabaseMissing('stored_images', ['id' => $oldId]);
        $this->assertDatabaseCount('stored_images', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->get($newUrl)->assertOk();
    }

    public function test_profile_reads_require_authentication_ownership_or_administrator(): void
    {
        $this->upload();
        $adminUrl = route('students.profile-photo.admin', ['student' => $this->student->id]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/students/profile/photo')->assertUnauthorized();
        $this->getJson($adminUrl)->assertUnauthorized();
        $this->actingAs($this->actor('ESTUDIANTE'))->getJson($adminUrl)->assertForbidden();
        $this->getJson('/api/v1/students/profile/photo')->assertNotFound();
        $admin = $this->actor('ADMINISTRADOR');
        $this->actingAs($admin)->getJson($adminUrl)->assertOk();
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson($adminUrl)->assertUnauthorized();
    }

    public function test_missing_profile_and_foreign_owner_reference_are_not_served(): void
    {
        $this->actingAs($this->student->user)->getJson('/api/v1/students/profile/photo')->assertNotFound();
        $this->upload();
        DB::table('stored_images')->update(['student_id' => $this->secondStudent()->id]);
        $this->getJson('/api/v1/students/profile/photo')->assertNotFound();
    }

    private function secondStudent(): Student
    {
        $other = $this->student->replicate();
        $other->user_id = $this->actor('ESTUDIANTE')->id;
        $other->sis_code = '000124';
        $other->identity_number = '12346';
        $other->save();

        return $other;
    }

    public function test_normal_profile_and_admin_queries_do_not_load_bytes(): void
    {
        $this->upload();
        DB::enableQueryLog();
        $profile = $this->getJson('/api/v1/students/profile')->assertOk()->assertJsonMissingPath('data.content');
        $this->assertStringNotContainsString('base64', $profile->getContent());
        $this->actingAs($this->actor('ADMINISTRADOR'))->getJson('/api/v1/admin/students/'.$this->student->id)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('stored_images', $query['query']);
        }
    }

    public function test_failed_audit_rolls_back_image_reference_and_preserves_previous_bytes(): void
    {
        $this->upload();
        $old = $this->student->user->profile_image_id;
        DB::table('stored_images')->where('id', $old)->first();
        DB::statement('DROP TABLE audit_logs'); // Isolated DB only; transaction must roll back new bytes.
        $this->postJson('/api/v1/students/profile/photo', ['photo' => RasterImageFixture::image()])->assertStatus(500);
        $this->assertSame($old, $this->student->user->fresh()->profile_image_id);
        $this->assertDatabaseCount('stored_images', 1);
        $this->get('/api/v1/students/profile/photo')->assertOk();
    }

    public function test_corrupt_bytes_fail_closed_instead_of_returning_a_different_image(): void
    {
        $this->upload();
        DB::table('stored_images')->update(['sha256' => str_repeat('0', 64)]);
        $this->getJson('/api/v1/students/profile/photo')->assertStatus(500);
    }

    public function test_legacy_local_photo_remains_private_and_is_replaced_without_migration(): void
    {
        $file = RasterImageFixture::image();
        Storage::disk('public')->put('avatars/legacy.png', file_get_contents($file->getRealPath()));
        $this->student->user->update(['profile_photo' => 'avatars/legacy.png']);
        $this->actingAs($this->student->user)->get('/api/v1/students/profile/photo')->assertOk();
        $this->upload();
        $this->assertNull($this->student->user->fresh()->profile_photo);
        $this->assertNotNull($this->student->user->fresh()->profile_image_id);
    }

    public function test_remote_and_traversal_legacy_references_are_not_fetched(): void
    {
        foreach (['https://example.com/photo.jpg', 'avatars/../../.env', 'avatars/..\\secret.png'] as $path) {
            $this->student->user->update(['profile_photo' => $path]);
            $this->actingAs($this->student->user)->getJson('/api/v1/students/profile/photo')->assertNotFound();
        }
    }

    public function test_oversized_disguised_svg_and_excessive_dimensions_are_rejected(): void
    {
        $this->actingAs($this->student->user);
        foreach ([RasterImageFixture::image()->size(2049),
            UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            RasterImageFixture::image('wide.png', 8001, 1)] as $file) {
            $this->postJson('/api/v1/students/profile/photo', ['photo' => $file])->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $this->assertDatabaseCount('stored_images', 0);
    }

    public function test_truncated_and_appended_image_containers_are_rejected(): void
    {
        $file = RasterImageFixture::image();
        $bytes = file_get_contents($file->getRealPath());
        $this->actingAs($this->student->user);
        foreach ([substr($bytes, 0, -5), $bytes.'<?php echo 1;'] as $invalid) {
            $this->postJson('/api/v1/students/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('invalid.png', $invalid)])
                ->assertUnprocessable()->assertJsonValidationErrors('photo');
        }
        $this->assertDatabaseCount('stored_images', 0);
    }

    public function test_local_optimization_when_gd_is_available_keeps_valid_metadata(): void
    {
        $this->actingAs($this->student->user)->postJson('/api/v1/students/profile/photo', ['photo' => RasterImageFixture::image('wide.png', 3000, 1)])->assertOk();
        $record = DB::table('stored_images')->first();
        $this->assertSame(function_exists('imagecreatefromstring') ? 2048 : 3000, (int) $record->width);
        $bytes = $this->bytes($record);
        $this->assertSame(hash('sha256', $bytes), $record->sha256);
        $this->assertSame(strlen($bytes), (int) $record->byte_size);
        $this->assertSame((int) $record->width, getimagesizefromstring($bytes)[0]);
    }

    public function test_foreign_keys_clear_deleted_image_references_and_cascade_owned_bytes(): void
    {
        $this->upload();
        DB::table('stored_images')->delete();
        $this->assertNull($this->student->user->fresh()->profile_image_id);
        $this->upload();
        $this->student->delete();
        $this->assertDatabaseCount('stored_images', 0);
        $this->assertNull(User::findOrFail($this->student->user_id)->profile_image_id);
    }
}
