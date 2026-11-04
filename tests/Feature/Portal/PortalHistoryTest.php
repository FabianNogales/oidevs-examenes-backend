<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\PortalEventCategory;
use App\Models\PortalInformationContent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/portal';

    private function actor(string $role = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    private function faq(): int
    {
        return $this->postJson(self::BASE.'/information/faqs', ['question' => 'Pregunta aprobada', 'answer' => 'Respuesta inicial'])->assertCreated()->json('data.id');
    }

    public function test_all_history_routes_require_active_administrator_and_are_not_public(): void
    {
        $paths = ['/events/1/history', '/news/1/history', '/services/1/history', '/information/sections/1/history', '/information/faqs/1/history'];
        foreach ($paths as $path) {
            $this->getJson(self::BASE.$path)->assertUnauthorized();
            $this->getJson('/api/v1/portal'.$path)->assertNotFound();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actingAs($this->actor($role));
            foreach ($paths as $path) {
                $this->getJson(self::BASE.$path)->assertForbidden();
            }
        }
        $admin = $this->actor();
        $admin->roles()->updateExistingPivot($admin->roles()->first()->id, ['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::BASE.'/events/1/history')->assertForbidden();
    }

    public function test_event_history_exposes_previous_values_and_cancellation_without_other_records(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin);
        $category = PortalEventCategory::create(['name' => 'Categoría de prueba']);
        $data = ['title' => 'Actividad inicial', 'description' => 'Contenido aprobado.', 'starts_at' => '2026-11-05T09:00:00-04:00', 'ends_at' => '2026-11-05T11:00:00-04:00', 'category_id' => $category->id];
        $id = $this->postJson(self::BASE.'/events', $data)->assertCreated()->json('data.id');
        $this->postJson(self::BASE.'/events', $data)->assertCreated();
        $this->patchJson(self::BASE."/events/{$id}", ['title' => 'Actividad corregida', 'revision' => 1])->assertOk();
        $this->patchJson(self::BASE."/events/{$id}/status", ['status' => 'CANCELLED', 'revision' => 2])->assertOk();
        $this->getJson(self::BASE."/events/{$id}/history")->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.new_values.status', 'CANCELLED')->assertJsonPath('data.0.actor_id', $admin->id)
            ->assertJsonPath('data.1.old_values.title', 'Actividad inicial')->assertJsonPath('data.1.new_values.title', 'Actividad corregida')
            ->assertJsonPath('data.2.old_values', null)->assertJsonPath('data.2.new_values.starts_at', '2026-11-05T09:00:00-04:00');
    }

    public function test_news_history_retains_text_and_publication_metadata_but_not_storage_paths(): void
    {
        $this->actingAs($this->actor());
        $id = $this->postJson(self::BASE.'/news', ['title' => 'Comunicado', 'summary' => 'Resumen', 'content' => 'Versión inicial'])->assertCreated()->json('data.id');
        $this->patchJson(self::BASE."/news/{$id}", ['content' => 'Versión corregida', 'revision' => 1])->assertOk();
        $this->patchJson(self::BASE."/news/{$id}/status", ['status' => 'PUBLISHED', 'revision' => 2])->assertOk();
        $this->patchJson(self::BASE."/news/{$id}/status", ['status' => 'ARCHIVED', 'revision' => 3])->assertOk();
        $this->getJson(self::BASE."/news/{$id}/history")->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.new_values.status', 'ARCHIVED')
            ->assertJsonPath('data.1.new_values.status', 'PUBLISHED')
            ->assertJsonPath('data.2.old_values.content', 'Versión inicial')->assertJsonPath('data.2.new_values.content', 'Versión corregida')
            ->assertJsonPath('data.0.new_values.has_image', false)->assertJsonMissingPath('data.0.new_values.image_path');
    }

    public function test_information_history_decodes_drafts_and_public_snapshots_and_checks_kind(): void
    {
        $this->actingAs($this->actor());
        $id = $this->faq();
        $path = self::BASE."/information/faqs/{$id}";
        $this->postJson($path.'/publish', ['revision' => 1])->assertOk();
        $this->patchJson($path, ['answer' => 'Respuesta corregida', 'revision' => 2])->assertOk();
        $this->getJson($path.'/history')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.old_values.draft.answer', 'Respuesta inicial')
            ->assertJsonPath('data.0.new_values.draft.answer', 'Respuesta corregida')
            ->assertJsonPath('data.0.new_values.published.answer', 'Respuesta inicial')
            ->assertJsonPath('data.1.new_values.is_published', true);
        $this->getJson(self::BASE."/information/sections/{$id}/history")->assertNotFound();
        $section = PortalInformationContent::where('kind', 'SECTION')->firstOrFail();
        $this->getJson(self::BASE."/information/sections/{$section->id}/history")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::BASE."/information/faqs/{$section->id}/history")->assertNotFound();
    }

    public function test_service_history_shows_changes_and_never_includes_other_entity_types(): void
    {
        $this->actingAs($this->actor());
        $id = $this->postJson(self::BASE.'/services', ['name' => 'Servicio inicial', 'description' => 'Descripción', 'icon' => 'link', 'url' => 'https://www.umss.edu.bo/'])->assertCreated()->json('data.id');
        $this->patchJson(self::BASE."/services/{$id}", ['name' => 'Servicio corregido', 'revision' => 1])->assertOk();
        $this->patchJson(self::BASE."/services/{$id}/status", ['status' => 'ACTIVE', 'revision' => 2])->assertOk();
        $this->faq();
        $before = AuditLog::count();
        $this->getJson(self::BASE."/services/{$id}/history")->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.entity_type', 'PortalService')->assertJsonPath('data.0.new_values.status', 'ACTIVE')
            ->assertJsonPath('data.1.old_values.name', 'Servicio inicial')->assertJsonPath('data.1.new_values.name', 'Servicio corregido');
        $this->assertSame($before, AuditLog::count());
    }

    public function test_history_allowlist_hides_unknown_secrets_and_global_audit_contract_is_unchanged(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin);
        $id = $this->faq();
        $log = AuditLog::create(['user_id' => $admin->id, 'entity_type' => 'PortalInformationContent', 'entity_id' => $id, 'action' => 'PORTAL_INFORMATION_DRAFT_UPDATED', 'created_at' => now(), 'new_values' => [
            'kind' => 'FAQ', 'revision' => 2, 'password' => 'never-expose', 'access_token' => 'never-expose', 'image_path' => 'private/file.png',
            'draft' => ['question' => 'Pregunta', 'answer' => 'Respuesta', 'sort_order' => 0, 'secret' => 'never-expose'],
        ]]);
        $this->getJson(self::BASE."/information/faqs/{$id}/history")->assertOk()
            ->assertJsonMissingPath('data.0.new_values.password')->assertJsonMissingPath('data.0.new_values.access_token')
            ->assertJsonMissingPath('data.0.new_values.image_path')->assertJsonMissingPath('data.0.new_values.draft.secret');
        $this->getJson('/api/v1/admin/audit-logs/'.$log->id)->assertOk()
            ->assertJsonPath('data.new_context', [])->assertJsonMissingPath('data.new_values');
    }

    public function test_history_pagination_missing_resources_and_read_only_methods(): void
    {
        $this->actingAs($this->actor());
        $id = $this->faq();
        $path = self::BASE."/information/faqs/{$id}";
        $this->patchJson($path, ['answer' => 'Respuesta corregida', 'revision' => 1])->assertOk();
        $this->getJson($path.'/history?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.action', 'PORTAL_INFORMATION_CREATED');
        $this->getJson($path.'/history?per_page=101')->assertUnprocessable();
        $this->getJson($path.'/history?page=0')->assertUnprocessable();
        foreach (['events', 'news', 'services', 'information/faqs', 'information/sections'] as $type) {
            $this->getJson(self::BASE."/{$type}/999/history")->assertNotFound();
        }
        $this->postJson($path.'/history')->assertStatus(405);
    }
}
