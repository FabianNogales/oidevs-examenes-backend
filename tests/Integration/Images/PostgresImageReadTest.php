<?php

namespace Tests\Integration\Images;

use App\Models\PortalNews;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\RasterImageFixture;
use Tests\TestCase;

/** Session-local PostgreSQL tables only. No migrations, seeders or persistent writes. */
class PostgresImageReadTest extends TestCase
{
    private bool $temporaryTransaction = false;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EIDA_TEST_TEMP_IMAGE_TABLES') !== '1') {
            $this->markTestSkipped('Opt in with EIDA_TEST_TEMP_IMAGE_TABLES=1; uses only temporary tables.');
        }
        $db = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached()
            || $db->getDriverName() !== 'pgsql' || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('Requires uncached testing configuration and local PostgreSQL.');
        }
        $db->beginTransaction();
        $this->temporaryTransaction = true;
        foreach (['users', 'students', 'careers', 'roles', 'role_user', 'stored_images', 'audit_logs', 'portal_news'] as $table) {
            // LIKE copies schema/checks, never rows or foreign keys. Replace serial
            // defaults so even fixture IDs cannot advance a persistent sequence.
            $db->statement("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING DEFAULTS INCLUDING CONSTRAINTS) ON COMMIT DROP");
            $columns = $db->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_default LIKE 'nextval(%'", [$table]);
            foreach ($columns as $column) {
                $name = $column->column_name;
                $sequence = 'image_read_'.$table.'_'.$name;
                $db->statement("CREATE TEMP SEQUENCE {$sequence}");
                $db->statement("ALTER TABLE pg_temp.{$table} ALTER COLUMN {$name} SET DEFAULT nextval('pg_temp.{$sequence}')");
            }
        }
        // Any missing fixture relation fails instead of falling through to public.
        $db->statement('SET LOCAL search_path TO pg_temp');
        $this->assertSame('pg_temp_', substr($db->selectOne('SELECT current_schema() AS name')->name, 0, 8));
        $user = $this->actor('ESTUDIANTE');
        $this->student = Student::forceCreate(['id' => 17, 'user_id' => $user->id, 'career_id' => 1,
            'sis_code' => 'TEMP001', 'identity_number' => 'TEMP001', 'first_names' => 'Fixture', 'last_names' => 'Imagen', 'status' => 'ACTIVE']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->temporaryTransaction) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
        } finally {
            parent::tearDown();
        }
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
        return $this->actingAs($this->student->user)->postJson('/api/v1/students/profile/photo', ['photo' => RasterImageFixture::image()])
            ->assertOk()->json('data.profile_photo_url');
    }

    public function test_postgresql_bytes_mime_hash_decoding_and_profile_replacement(): void
    {
        $url = $this->upload();
        $record = DB::table('stored_images')->first();
        $this->assertIsResource($record->content);
        $bytes = stream_get_contents($record->content);
        $this->assertSame(17, (int) $record->student_id);
        $this->assertNotSame($this->student->id, $this->student->user_id);
        $response = $this->getJson($url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Length', (string) strlen($bytes));
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame($record->sha256, hash('sha256', $bytes));
        $this->assertSame('image/png', getimagesizefromstring($bytes)['mime']);
        if (function_exists('imagecreatefromstring')) {
            $decoded = imagecreatefromstring($bytes);
            $this->assertNotFalse($decoded);
            imagedestroy($decoded);
        }
        $raw = DB::selectOne("SELECT encode(content, 'hex') AS hex FROM stored_images WHERE id = ?", [$record->id]);
        $this->assertSame(bin2hex($bytes), $raw->hex);
        $next = $this->upload();
        $this->assertNotSame($url, $next);
        $this->getJson($next)->assertOk();
        $this->assertDatabaseCount('stored_images', 1);
    }

    public function test_profile_ownership_missing_image_and_admin_permissions(): void
    {
        $this->actingAs($this->student->user)->getJson('/api/v1/students/profile/photo')->assertNotFound();
        $this->upload();
        $adminUrl = '/api/v1/admin/students/17/profile-photo';
        $this->getJson($adminUrl)->assertForbidden();
        $this->actingAs($this->actor('ADMINISTRADOR'))->getJson($adminUrl)->assertOk();
        $this->actingAs($this->actor('ESTUDIANTE'))->getJson('/api/v1/students/profile/photo')->assertNotFound();
        DB::table('stored_images')->update(['student_id' => 99]);
        $this->actingAs($this->student->user)->getJson('/api/v1/students/profile/photo')->assertNotFound();
    }

    public function test_historical_image_uses_the_same_binary_reader_and_stays_private(): void
    {
        $this->actingAs($this->actor('ADMINISTRADOR'))
            ->postJson('/api/v1/admin/students/17/historical-photo', ['revision' => 0, 'photo' => RasterImageFixture::image()])->assertCreated();
        $url = '/api/v1/admin/students/17/historical-photo';
        $response = $this->getJson($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame('image/png', getimagesizefromstring($response->streamedContent())['mime']);
        $this->actingAs($this->student->user)->getJson($url)->assertForbidden();
    }

    public function test_news_image_is_public_only_while_published(): void
    {
        $this->actingAs($this->actor('ADMINISTRADOR'));
        $news = $this->postJson('/api/v1/admin/portal/news', ['title' => 'Fixture temporal', 'summary' => 'Resumen', 'content' => 'Contenido'])
            ->assertCreated()->json('data');
        $id = $news['id'];
        $news = $this->postJson("/api/v1/admin/portal/news/{$id}/image", ['revision' => $news['revision'], 'image' => RasterImageFixture::image()])
            ->assertOk()->json('data');
        $public = "/api/v1/portal/news/{$id}/image";
        $this->getJson($public)->assertNotFound();
        $this->patchJson("/api/v1/admin/portal/news/{$id}/status", ['revision' => $news['revision'], 'status' => 'PUBLISHED'])->assertOk();
        $this->app['auth']->forgetGuards();
        $response = $this->getJson($public)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame('image/png', getimagesizefromstring($response->streamedContent())['mime']);
        PortalNews::findOrFail($id)->forceFill(['status' => 'ARCHIVED'])->save();
        $this->getJson($public)->assertNotFound();
        $this->getJson('/api/v1/students/profile/photo')->assertUnauthorized();
    }
}
