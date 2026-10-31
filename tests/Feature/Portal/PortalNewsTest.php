<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\PortalNews;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PortalNewsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin/portal/news';

    private const PUBLIC = '/api/v1/portal/news';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function actor(string $role = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'title' => 'Comunicado institucional',
            'summary' => 'Resumen del comunicado.',
            'content' => "Contenido completo.\nSegunda línea.",
        ], $changes);
    }

    private function news(string $status = 'DRAFT', array $changes = []): PortalNews
    {
        $this->actingAs($this->actor());
        $response = $this->postJson(self::ADMIN, $this->payload($changes))->assertCreated();
        $news = PortalNews::findOrFail($response->json('data.id'));
        if ($status !== 'DRAFT') {
            $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => $status, 'revision' => 1])->assertOk();
        }

        return $news->refresh();
    }

    private function upload(PortalNews $news, ?int $revision = null): void
    {
        $this->postJson(self::ADMIN."/{$news->id}/image", [
            'revision' => $revision ?? $news->revision,
            'image' => UploadedFile::fake()->image('news.png'),
        ])->assertOk();
        $news->refresh();
    }

    public function test_empty_public_listing_requires_no_authentication_and_does_not_write(): void
    {
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC.'/999')->assertNotFound();
        $this->getJson(self::PUBLIC.'/999/image')->assertNotFound();
        $this->assertDatabaseCount('portal_news', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_all_administration_routes_require_authentication_and_admin_role(): void
    {
        $routes = [
            ['GET', self::ADMIN], ['GET', self::ADMIN.'/999'], ['POST', self::ADMIN],
            ['PATCH', self::ADMIN.'/999'], ['PATCH', self::ADMIN.'/999/status'],
            ['GET', self::ADMIN.'/999/image'], ['POST', self::ADMIN.'/999/image'], ['DELETE', self::ADMIN.'/999/image'],
        ];
        foreach ($routes as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actingAs($this->actor($role));
            foreach ($routes as [$method, $url]) {
                $this->json($method, $url)->assertForbidden();
            }
        }
    }

    public function test_inactive_accounts_roles_assignments_and_first_access_are_blocked(): void
    {
        $admin = $this->actor();
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertUnauthorized();
        $admin = $this->actor();
        $role = $admin->roles()->first();
        $role->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden();
        $role->update(['status' => 'ACTIVE']);
        $admin->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
        $this->getJson(self::ADMIN)->assertForbidden();
        $admin->roles()->updateExistingPivot($role->id, ['status' => 'ACTIVE']);
        $admin->forceFill(['must_change_password' => true])->save();
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_creation_is_draft_and_publication_exposes_only_public_fields(): void
    {
        $news = $this->news();
        $this->getJson(self::PUBLIC."/{$news->id}")->assertNotFound();
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data');
        $this->getJson(self::ADMIN."/{$news->id}")->assertJsonPath('data.revision', 1)->assertJsonPath('data.published_at', null);
        $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => 'PUBLISHED', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.published_by', $news->created_by);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/{$news->id}")->assertOk()->assertJsonPath('data.content', $news->content)
            ->assertJsonPath('data.image_url', null)->assertJsonMissingPath('data.revision')
            ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.published_by')->assertJsonMissingPath('data.image_path');
        $this->getJson(self::PUBLIC)->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.content');
    }

    public function test_unpublishing_archiving_and_republishing_preserve_content_and_first_publication(): void
    {
        $news = $this->news('PUBLISHED');
        $firstPublication = $news->published_at->toIso8601String();
        foreach (['DRAFT', 'PUBLISHED', 'ARCHIVED'] as $status) {
            $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => $status, 'revision' => $news->revision])->assertOk();
            $news->refresh();
            $this->assertSame($firstPublication, $news->published_at->toIso8601String());
            $response = $this->getJson(self::PUBLIC."/{$news->id}");
            $status === 'PUBLISHED' ? $response->assertOk() : $response->assertNotFound();
        }
        $this->getJson(self::ADMIN."/{$news->id}")->assertJsonPath('data.status', 'ARCHIVED');
        $this->assertDatabaseCount('portal_news', 1);
        $this->assertSame(5, AuditLog::where('entity_type', 'PortalNews')->count());
        $this->deleteJson(self::ADMIN."/{$news->id}")->assertStatus(405);
    }

    public function test_stale_text_status_and_image_operations_return_409_without_overwriting(): void
    {
        $news = $this->news();
        $this->patchJson(self::ADMIN."/{$news->id}", ['title' => 'Nuevo título', 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson(self::ADMIN."/{$news->id}", ['title' => 'Viejo', 'revision' => 1])->assertConflict()->assertJsonPath('code', 'REVISION_CONFLICT');
        $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => 'PUBLISHED', 'revision' => 1])->assertConflict();
        $this->postJson(self::ADMIN."/{$news->id}/image", ['revision' => 1, 'image' => UploadedFile::fake()->image('photo.jpg')])
            ->assertConflict()->assertJsonPath('current_revision', 2);
        $this->deleteJson(self::ADMIN."/{$news->id}/image", ['revision' => 1])->assertConflict();
        $this->assertSame('Nuevo título', $news->refresh()->title);
        $this->assertSame('DRAFT', $news->status);
        $this->assertSame([], Storage::disk('local')->allFiles('portal-news'));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_required_lengths_and_managed_fields_are_validated(): void
    {
        $this->actingAs($this->actor());
        foreach ([
            ['title' => ''], ['title' => str_repeat('x', 201)], ['summary' => str_repeat('x', 1001)],
            ['content' => ''], ['content' => str_repeat('x', 50001)], ['status' => 'PUBLISHED'],
            ['image_path' => 'secret'], ['image_url' => 'https://example.com/a.jpg'],
            ['published_at' => now()->toIso8601String()], ['published_by' => 999], ['created_by' => 999],
        ] as $changes) {
            $this->postJson(self::ADMIN, $this->payload($changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('portal_news', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_revision_is_required_for_mutations_and_unknown_status_is_rejected(): void
    {
        $news = $this->news();
        $this->patchJson(self::ADMIN."/{$news->id}", ['title' => 'Nuevo'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => 'ACTIVE', 'revision' => 1])->assertUnprocessable();
        $this->postJson(self::ADMIN."/{$news->id}/image", ['image' => UploadedFile::fake()->image('x.png')])->assertUnprocessable();
        $this->deleteJson(self::ADMIN."/{$news->id}/image")->assertUnprocessable();
        $this->assertSame(1, $news->refresh()->revision);
    }

    public function test_search_pagination_order_and_status_filter_preserve_public_visibility(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $older = $this->news('PUBLISHED', ['title' => 'Comunicado 100%']);
        $this->travel(1)->days();
        $newer = $this->news('PUBLISHED', ['title' => 'Comunicado reciente']);
        $sameDate = $this->news('PUBLISHED', ['title' => 'Comunicado más reciente']);
        $private = $this->news();
        $this->getJson(self::PUBLIC.'?per_page=1')->assertOk()->assertJsonPath('data.0.id', $sameDate->id)->assertJsonPath('meta.total', 3);
        $this->getJson(self::PUBLIC.'?per_page=1&page=2')->assertJsonPath('data.0.id', $newer->id);
        $this->getJson(self::PUBLIC.'?search=100%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->id);
        $this->getJson(self::PUBLIC.'?search=COMUNICADO&status=DRAFT')->assertJsonCount(3, 'data');
        $this->getJson(self::ADMIN.'?status=DRAFT')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $private->id);
        $this->getJson(self::PUBLIC.'?search=ausente')->assertJsonCount(0, 'data');
        $this->getJson(self::PUBLIC.'?page=0')->assertUnprocessable();
        $this->getJson(self::PUBLIC.'?per_page=101')->assertUnprocessable();
    }

    public function test_image_is_private_until_published_and_disappears_from_public_access_when_archived(): void
    {
        $news = $this->news();
        $this->upload($news);
        Storage::disk('local')->assertExists($news->image_path);
        $this->getJson(self::ADMIN."/{$news->id}")->assertJsonPath('data.image_url', url(self::ADMIN."/{$news->id}/image"));
        $this->get(self::ADMIN."/{$news->id}/image")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(self::PUBLIC."/{$news->id}/image")->assertNotFound();
        $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => 'PUBLISHED', 'revision' => $news->revision])->assertOk();
        $this->getJson(self::PUBLIC."/{$news->id}")->assertJsonPath('data.image_url', url(self::PUBLIC."/{$news->id}/image"));
        $this->get(self::PUBLIC."/{$news->id}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        $news->refresh();
        $this->patchJson(self::ADMIN."/{$news->id}/status", ['status' => 'ARCHIVED', 'revision' => $news->revision])->assertOk();
        $this->get(self::PUBLIC."/{$news->id}/image")->assertNotFound();
        Storage::disk('local')->assertExists($news->image_path);
    }

    public function test_replacement_removes_old_image_after_commit_and_deletion_returns_null(): void
    {
        $news = $this->news();
        $this->upload($news);
        $oldPath = $news->image_path;
        $this->upload($news);
        $this->assertNotSame($oldPath, $news->image_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($news->image_path);
        $newPath = $news->image_path;
        $this->deleteJson(self::ADMIN."/{$news->id}/image", ['revision' => $news->revision])
            ->assertOk()->assertJsonPath('data.image_url', null)->assertJsonPath('data.revision', 4);
        Storage::disk('local')->assertMissing($newPath);
        $this->get(self::ADMIN."/{$news->id}/image")->assertNotFound();
    }

    public function test_image_upload_rejects_oversize_svg_gif_and_disguised_files(): void
    {
        $news = $this->news();
        foreach ([
            UploadedFile::fake()->image('large.jpg')->size(5121),
            UploadedFile::fake()->image('animation.gif'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo "bad";'),
        ] as $image) {
            $this->postJson(self::ADMIN."/{$news->id}/image", ['revision' => 1, 'image' => $image])
                ->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertSame([], Storage::disk('local')->allFiles('portal-news'));
        $this->assertSame(1, $news->refresh()->revision);
    }

    public function test_jpeg_and_webp_are_accepted(): void
    {
        $news = $this->news();
        foreach (['jpg', 'webp'] as $extension) {
            $this->postJson(self::ADMIN."/{$news->id}/image", [
                'revision' => $news->revision,
                'image' => UploadedFile::fake()->image('photo.'.$extension),
            ])->assertOk();
            $news->refresh();
            Storage::disk('local')->assertExists($news->image_path);
        }
    }

    public function test_failed_image_audit_keeps_previous_file_and_reference_and_cleans_new_upload(): void
    {
        $news = $this->news();
        $this->upload($news);
        $oldPath = $news->image_path;
        $revision = $news->revision;
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson(self::ADMIN."/{$news->id}/image", ['revision' => $revision, 'image' => UploadedFile::fake()->image('new.png')])->assertStatus(500);
        $this->assertSame($oldPath, $news->refresh()->image_path);
        $this->assertSame($revision, $news->revision);
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles('portal-news'));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_failed_creation_audit_rolls_back_the_news(): void
    {
        $this->actingAs($this->actor());
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson(self::ADMIN, $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('portal_news', 0);
    }

    public function test_public_reads_do_not_modify_existing_modules_or_audit(): void
    {
        $news = $this->news('PUBLISHED');
        $tables = ['portal_news', 'portal_events', 'audit_logs', 'exams', 'exam_eligibilities', 'exam_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->getJson(self::PUBLIC)->assertOk();
        $this->getJson(self::PUBLIC."/{$news->id}")->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
    }

    public function test_missing_news_cannot_be_edited_or_given_an_image(): void
    {
        $this->actingAs($this->actor());
        $this->patchJson(self::ADMIN.'/999', ['title' => 'Nuevo', 'revision' => 1])->assertNotFound();
        $this->patchJson(self::ADMIN.'/999/status', ['status' => 'PUBLISHED', 'revision' => 1])->assertNotFound();
        $this->postJson(self::ADMIN.'/999/image', ['revision' => 1, 'image' => UploadedFile::fake()->image('x.png')])->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('portal-news'));
    }
}
