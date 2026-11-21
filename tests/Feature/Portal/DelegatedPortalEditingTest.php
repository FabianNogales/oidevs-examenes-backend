<?php

namespace Tests\Feature\Portal;

use App\Models\Permission;
use App\Models\PortalEvent;
use App\Models\PortalEventCategory;
use App\Models\PortalInformationContent;
use App\Models\PortalNews;
use App\Models\PortalService;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\RolePermissionCatalog;
use App\Services\Portal\PortalEditing;
use App\Services\Portal\PortalNewsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DelegatedPortalEditingTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin/portal/';

    private ?User $contentOwner = null;

    private function ownerId(): int
    {
        return ($this->contentOwner ??= User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]))->id;
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite' || $connection['database'] !== ':memory:'
            || ! empty($connection['url']) || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Portal tests require testing, SQLite :memory: and array session/cache.');
        }
        foreach ($app['db']->connection()->getPdo()->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '') {
                throw new \RuntimeException('Persistent test connection rejected.');
            }
        }

        return $app;
    }

    private function actor(array $keys = [], string $name = 'Editor institucional'): User
    {
        $custom = ! RolePermissionCatalog::reservedName($name);
        $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
        $role->forceFill(['is_custom' => $custom,
            'custom_name_key' => $custom ? mb_strtoupper($name) : null,
        ])->save();
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function news(string $status = 'PUBLISHED'): PortalNews
    {
        $item = new PortalNews;
        $item->forceFill(['title' => 'Noticia institucional', 'summary' => 'Resumen institucional',
            'content' => 'Texto original', 'status' => $status, 'revision' => 1,
            'created_by' => $this->ownerId(), 'updated_by' => $this->ownerId(),
            'published_at' => $status === 'PUBLISHED' ? now() : null])->save();

        return $item;
    }

    private function information(string $kind, bool $published = true): PortalInformationContent
    {
        $draft = $kind === 'SECTION'
            ? ['title' => 'Información institucional', 'description' => 'Descripción original', 'links' => [], 'sort_order' => 0]
            : ['question' => '¿Cómo usar EIDA?', 'answer' => 'Respuesta original', 'sort_order' => 0];
        $item = new PortalInformationContent;
        $item->forceFill(['kind' => $kind, 'key' => $kind === 'SECTION' ? 'test_direct_edit_'.PortalInformationContent::count() : null,
            'draft' => $draft, 'published' => $published ? $draft : null, 'is_published' => $published,
            'created_by' => $this->ownerId(), 'updated_by' => $this->ownerId(),
            'published_at' => $published ? now() : null, 'revision' => 1])->save();

        return $item;
    }

    private function event(string $visibility = 'PUBLIC'): PortalEvent
    {
        return PortalEvent::create(['title' => 'Evento institucional', 'description' => 'Descripción original',
            'category_id' => PortalEventCategory::where('is_active', true)->firstOrFail()->id,
            'starts_at' => '2027-03-05T13:00:00Z', 'ends_at' => '2027-03-05T15:00:00Z',
            'all_day' => false, 'status' => 'PUBLISHED', 'visibility' => $visibility,
            'created_by' => $this->ownerId(), 'updated_by' => $this->ownerId(),
            'published_at' => now(), 'revision' => 1]);
    }

    private function service(): PortalService
    {
        $item = new PortalService;
        $item->forceFill(['name' => 'WebSISS', 'description' => 'Servicio original', 'icon_key' => 'academic',
            'destination_type' => 'EXTERNAL', 'destination' => 'https://websiss.umss.edu.bo/',
            'requires_auth' => false, 'sort_order' => 0, 'status' => 'ACTIVE', 'revision' => 1,
            'created_by' => $this->ownerId(), 'updated_by' => $this->ownerId()])->save();

        return $item;
    }

    public function test_news_editor_updates_public_text_and_audit_actor_without_changing_publication(): void
    {
        $news = $this->news();
        $publishedAt = $news->published_at->toISOString();
        $actor = $this->actor(['portal.news.update']);
        $this->getJson(self::ADMIN.'news')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ADMIN.'news/'.$news->id)->assertOk();
        $this->patchJson(self::ADMIN.'news/'.$news->id, ['content' => 'Contenido corregido', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED')->assertJsonPath('data.updated_by', $actor->id)
            ->assertJsonPath('data.revision', 2);
        $this->getJson('/api/v1/portal/news/'.$news->id)->assertOk()->assertJsonPath('data.content', 'Contenido corregido');
        $this->assertSame($publishedAt, $news->refresh()->published_at->toISOString());
        $this->assertDatabaseHas('audit_logs', ['user_id' => $actor->id, 'action' => 'PORTAL_NEWS_UPDATED',
            'entity_type' => 'PortalNews', 'entity_id' => $news->id]);
        $this->patchJson(self::ADMIN.'news/'.$news->id, ['content' => 'Edición obsoleta', 'revision' => 1])->assertConflict();
        $this->assertSame('Contenido corregido', $news->refresh()->content);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_news_draft_is_editable_but_remains_outside_public_queries(): void
    {
        $news = $this->news('DRAFT');
        $this->actor(['portal.news.update']);
        $this->patchJson(self::ADMIN.'news/'.$news->id, ['title' => 'Borrador corregido', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.published_at', null);
        $this->getJson('/api/v1/portal/news/'.$news->id)->assertNotFound();
        $this->getJson('/api/v1/portal/news')->assertJsonCount(0, 'data');
    }

    public function test_each_section_permission_reads_and_edits_only_its_own_catalog(): void
    {
        $news = $this->news();
        $event = $this->event();
        $section = $this->information('SECTION');
        $faq = $this->information('FAQ');
        $service = $this->service();
        $targets = [
            'news' => [$news, ['summary' => 'Resumen corregido']],
            'events' => [$event, ['description' => 'Evento corregido']],
            'information/sections' => [$section, ['description' => 'Sección corregida']],
            'information/faqs' => [$faq, ['answer' => 'Respuesta corregida']],
            'services' => [$service, ['description' => 'Servicio corregido']],
        ];
        foreach (array_values(PortalEditing::PERMISSIONS) as $index => $permission) {
            $this->actor([$permission], 'Editor '.$index);
            foreach (array_keys($targets) as $otherIndex => $path) {
                [$item, $input] = $targets[$path];
                if ($index === $otherIndex) {
                    $this->getJson(self::ADMIN.$path)->assertOk();
                    $this->getJson(self::ADMIN.$path.'/'.$item->id)->assertOk();
                    $this->patchJson(self::ADMIN.$path.'/'.$item->id, $input + ['revision' => 1])->assertOk();
                } else {
                    $this->getJson(self::ADMIN.$path)->assertForbidden();
                    $this->getJson(self::ADMIN.$path.'/'.$item->id)->assertForbidden();
                    $this->patchJson(self::ADMIN.$path.'/'.$item->id, $input + ['revision' => 1])->assertForbidden();
                }
            }
            $this->getJson('/api/v1/admin/roles')->assertForbidden();
            $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        }
    }

    public function test_information_and_faq_save_update_live_snapshot_but_never_publish_a_draft(): void
    {
        foreach (['SECTION' => 'sections', 'FAQ' => 'faqs'] as $kind => $path) {
            $actor = $this->actor(['portal.information.'.$path.'.update'], 'Editor '.$path);
            foreach ([true, false] as $published) {
                $item = $this->information($kind, $published);
                $field = $kind === 'SECTION' ? 'description' : 'answer';
                $this->patchJson(self::ADMIN.'information/'.$path.'/'.$item->id, [$field => 'Texto corregido', 'revision' => 1])
                    ->assertOk()->assertJsonPath('data.is_published', $published)->assertJsonPath('data.updated_by', $actor->id)
                    ->assertJsonPath('data.published', $published ? array_replace($item->draft, [$field => 'Texto corregido']) : null);
                $public = $this->getJson('/api/v1/portal/information')->assertOk()->json('data.'.$path);
                $visible = collect($public)->firstWhere('id', $item->id);
                $published ? $this->assertSame('Texto corregido', $visible[$field]) : $this->assertNull($visible);
            }
        }
        // Shared storage does not bypass the existing kind restriction.
        $faq = $this->information('FAQ');
        $this->actor(['portal.information.sections.update'], 'Solo secciones');
        $this->getJson(self::ADMIN.'information/sections/'.$faq->id)->assertNotFound();
        $this->patchJson(self::ADMIN.'information/sections/'.$faq->id, ['title' => 'Otro tipo', 'revision' => 1])->assertNotFound();
    }

    public function test_calendar_and_service_keep_public_visibility_access_flags_and_business_validation(): void
    {
        $event = $this->event();
        $private = $this->event('PRIVATE');
        $service = $this->service();
        $this->actor(array_values(PortalEditing::PERMISSIONS));
        $this->getJson(self::ADMIN.'event-categories')->assertOk();
        foreach ([$event, $private] as $item) {
            $this->patchJson(self::ADMIN.'events/'.$item->id, ['title' => 'Calendario corregido', 'revision' => 1])->assertOk()
                ->assertJsonPath('data.status', 'PUBLISHED')->assertJsonPath('data.visibility', $item->visibility);
        }
        $this->getJson('/api/v1/portal/events/'.$event->id)->assertOk()->assertJsonPath('data.title', 'Calendario corregido');
        $this->getJson('/api/v1/portal/events/'.$private->id)->assertNotFound();
        $this->patchJson(self::ADMIN.'events/'.$event->id, ['ends_at' => '2027-03-01T15:00:00+00:00', 'revision' => 2])->assertUnprocessable();
        $this->patchJson(self::ADMIN.'services/'.$service->id, ['description' => 'Servicio corregido', 'revision' => 1])->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');
        $this->getJson('/api/v1/portal/services/'.$service->id)->assertOk()->assertJsonPath('data.description', 'Servicio corregido');
        $this->assertSame('EXTERNAL', $service->refresh()->destination_type);
        $this->assertFalse($service->requires_auth);
        $this->patchJson(self::ADMIN.'services/'.$service->id, ['url' => 'https://unrelated.example/', 'revision' => 2])->assertUnprocessable();
        $service->forceFill(['requires_auth' => true])->save();
        $this->patchJson(self::ADMIN.'services/'.$service->id, ['description' => 'Acceso conservado', 'revision' => 2])->assertOk();
        $this->assertTrue($service->refresh()->requires_auth);
        $service->forceFill(['destination_type' => 'INTERNAL', 'destination' => '/private'])->save();
        $before = $service->getAttributes();
        $this->patchJson(self::ADMIN.'services/'.$service->id, ['description' => 'No convertir', 'revision' => 3])->assertUnprocessable();
        $this->assertSame($before, $service->refresh()->getAttributes());
    }

    public function test_forbidden_payload_fields_are_rejected_atomically_even_when_empty_or_unchanged(): void
    {
        $news = $this->news();
        $event = $this->event();
        $section = $this->information('SECTION');
        $faq = $this->information('FAQ');
        $service = $this->service();
        $this->actor(array_values(PortalEditing::PERMISSIONS));
        $cases = [
            ['news', $news, ['title' => 'No guardar'], ['status' => 'DRAFT', 'published_at' => null, 'image' => null,
                'category_id' => DB::table('portal_news_categories')->value('id')]],
            ['events', $event, ['title' => 'No guardar'], ['visibility' => 'PUBLIC', 'status' => 'DRAFT']],
            ['information/sections', $section, ['description' => 'No guardar'], ['is_published' => false, 'published' => null]],
            ['information/faqs', $faq, ['answer' => 'No guardar'], ['is_published' => true, 'kind' => null]],
            ['services', $service, ['description' => 'No guardar'], ['status' => 'INACTIVE', 'requires_auth' => null, 'destination_type' => 'EXTERNAL']],
        ];
        foreach ($cases as [$path, $item, $valid, $forbidden]) {
            $before = $item->getAttributes();
            foreach ($forbidden as $field => $value) {
                $this->patchJson(self::ADMIN.$path.'/'.$item->id, $valid + [$field => $value, 'revision' => 1])
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertSame($before, $item->refresh()->getAttributes());
            }
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_all_delegated_permissions_still_deny_creation_state_images_and_history(): void
    {
        $news = $this->news();
        $event = $this->event();
        $section = $this->information('SECTION');
        $faq = $this->information('FAQ');
        $service = $this->service();
        $this->actor(array_values(PortalEditing::PERMISSIONS));
        foreach (['news' => $news, 'events' => $event, 'services' => $service] as $path => $item) {
            $this->postJson(self::ADMIN.$path, [])->assertForbidden();
            $this->patchJson(self::ADMIN.$path.'/'.$item->id.'/status', ['status' => 'DRAFT', 'revision' => 1])->assertForbidden();
            $this->getJson(self::ADMIN.$path.'/'.$item->id.'/history')->assertForbidden();
        }
        foreach (['sections' => $section, 'faqs' => $faq] as $path => $item) {
            foreach (['publish', 'unpublish'] as $action) {
                $this->postJson(self::ADMIN.'information/'.$path.'/'.$item->id.'/'.$action, ['revision' => 1])->assertForbidden();
            }
            $this->getJson(self::ADMIN.'information/'.$path.'/'.$item->id.'/history')->assertForbidden();
        }
        $this->postJson(self::ADMIN.'information/faqs', [])->assertForbidden();
        $this->postJson(self::ADMIN.'news/'.$news->id.'/image', ['revision' => 1])->assertForbidden();
        $this->deleteJson(self::ADMIN.'news/'.$news->id.'/image', ['revision' => 1])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_permission_removal_is_effective_on_the_next_request_and_inside_the_save_service(): void
    {
        $news = $this->news();
        $actor = $this->actor(['portal.news.update']);
        $this->getJson(self::ADMIN.'news')->assertOk();
        DB::table('permission_role')->where('role_id', $actor->roles()->first()->id)->delete();
        $this->getJson(self::ADMIN.'news')->assertForbidden();
        $this->patchJson(self::ADMIN.'news/'.$news->id, ['title' => 'No autorizado', 'revision' => 1])->assertForbidden();
        $request = Request::create('/', 'PATCH');
        $request->setUserResolver(fn () => $actor);
        try {
            app(PortalNewsService::class)->save(['title' => 'No autorizado', 'revision' => 1], $request, $news->id);
            $this->fail('Save service must recheck effective permissions.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('Noticia institucional', $news->refresh()->title);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_catalog_hu22_and_web_me_reuse_the_existing_role_flow_and_revoke_live_sessions(): void
    {
        $admin = $this->actor([], 'ADMINISTRADOR');
        $keys = array_values(PortalEditing::PERMISSIONS);
        $catalog = $this->getJson('/api/v1/admin/permissions')->assertOk()->json('data');
        foreach ($keys as $key) {
            $this->assertSame(1, collect($catalog)->where('key', $key)->where('is_enabled', true)->count());
        }
        $role = $this->postJson('/api/v1/admin/roles', ['name' => 'Comunicaciones', 'permission_keys' => $keys])
            ->assertCreated()->json('data.id');
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->postJson('/api/v1/admin/users/'.$user->id.'/roles', ['role_id' => $role])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->refresh()->withAccessToken(null), 'web');
        sort($keys);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.permissions', $keys);
        $this->getJson(self::ADMIN.'news')->assertOk();
        $marker = $user->active_session_id;
        $user->createToken('live-portal-session');
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/roles/'.$role, ['name' => 'Comunicaciones', 'permission_keys' => [], 'revision' => 1])->assertOk();
        $this->assertNotSame($marker, $user->refresh()->active_session_id);
        $this->assertSame(0, $user->tokens()->count());
        Sanctum::actingAs($user);
        $this->getJson(self::ADMIN.'news')->assertForbidden();
    }

    public function test_inactive_first_access_base_roles_and_inactive_assignments_keep_their_guards(): void
    {
        $user = $this->actor(['portal.news.update']);
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson(self::ADMIN.'news')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->getJson(self::ADMIN.'news')->assertUnauthorized();
        $user->forceFill(['status' => 'ACTIVE'])->save();
        $user->roles()->updateExistingPivot($user->roles()->first()->id, ['status' => 'INACTIVE']);
        $this->getJson(self::ADMIN.'news')->assertForbidden();
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actor([], $role);
            foreach (['news', 'events', 'information/sections', 'information/faqs', 'services'] as $path) {
                $this->getJson(self::ADMIN.$path)->assertForbidden();
            }
        }
    }

    public function test_administrator_keeps_creation_publication_visibility_and_history_without_permission_links(): void
    {
        $this->actor([], 'ADMINISTRADOR');
        $news = $this->postJson(self::ADMIN.'news', ['title' => 'Nueva noticia', 'summary' => 'Resumen', 'content' => 'Contenido'])
            ->assertCreated()->json('data.id');
        $this->patchJson(self::ADMIN.'news/'.$news.'/status', ['status' => 'PUBLISHED', 'revision' => 1])->assertOk();
        $this->getJson(self::ADMIN.'news/'.$news.'/history')->assertOk();
        $event = $this->event('PRIVATE');
        $this->patchJson(self::ADMIN.'events/'.$event->id, ['visibility' => 'PUBLIC', 'revision' => 1])->assertOk()
            ->assertJsonPath('data.visibility', 'PUBLIC');
        $faq = $this->postJson(self::ADMIN.'information/faqs', ['question' => 'Pregunta nueva', 'answer' => 'Respuesta'])->assertCreated()->json('data.id');
        $this->postJson(self::ADMIN.'information/faqs/'.$faq.'/publish', ['revision' => 1])->assertOk();
        $this->postJson(self::ADMIN.'information/faqs/'.$faq.'/unpublish', ['revision' => 2])->assertOk();
        $service = $this->service();
        $this->patchJson(self::ADMIN.'services/'.$service->id.'/status', ['status' => 'INACTIVE', 'revision' => 1])->assertOk();
        $this->assertSame(0, DB::table('permission_role')->count());
    }
}

