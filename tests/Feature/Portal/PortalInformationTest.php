<?php

namespace Tests\Feature\Portal;

use App\Models\PortalInformationContent;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PortalInformationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin/portal/information';

    private const PUBLIC = '/api/v1/portal/information';

    private function actor(string $role = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    private function section(): PortalInformationContent
    {
        return PortalInformationContent::where('key', 'about')->firstOrFail();
    }

    private function faq(string $question = '¿Cómo funciona EIDA?', int $order = 1): PortalInformationContent
    {
        $response = $this->postJson(self::ADMIN.'/faqs', ['question' => $question, 'answer' => 'Respuesta autorizada.', 'sort_order' => $order])->assertCreated();

        return PortalInformationContent::findOrFail($response->json('data.id'));
    }

    private function publish(PortalInformationContent $item): void
    {
        $type = $item->kind === 'SECTION' ? 'sections' : 'faqs';
        $this->postJson(self::ADMIN."/{$type}/{$item->id}/publish", ['revision' => $item->revision])->assertOk();
        $item->refresh();
    }

    public function test_public_endpoint_starts_empty_without_invented_institutional_content(): void
    {
        $this->getJson(self::PUBLIC)->assertOk()->assertExactJson(['data' => ['sections' => [], 'faqs' => []]]);
        $this->assertDatabaseCount('portal_information_contents', 6);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(['about', 'objectives', 'student_guide', 'teacher_guide', 'collaborator_guide', 'contact'], PortalInformationContent::orderBy('id')->pluck('key')->all());
    }

    public function test_administrative_routes_require_authentication_and_admin_for_every_operation(): void
    {
        $routes = [['GET', '/sections'], ['GET', '/faqs'], ['POST', '/faqs']];
        foreach (['sections', 'faqs'] as $type) {
            $routes = array_merge($routes, [['GET', "/{$type}/1"], ['PATCH', "/{$type}/1"], ['POST', "/{$type}/1/publish"], ['POST', "/{$type}/1/unpublish"]]);
        }
        foreach ($routes as [$method, $path]) {
            $this->json($method, self::ADMIN.$path)->assertUnauthorized();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actingAs($this->actor($role));
            foreach ($routes as [$method, $path]) {
                $this->json($method, self::ADMIN.$path)->assertForbidden();
            }
        }
    }

    public function test_inactive_accounts_roles_assignments_and_first_access_are_rejected(): void
    {
        $admin = $this->actor();
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN.'/sections')->assertUnauthorized();
        $admin = $this->actor();
        $role = $admin->roles()->first();
        $role->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN.'/sections')->assertForbidden();
        $role->update(['status' => 'ACTIVE']);
        $admin->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
        $this->getJson(self::ADMIN.'/sections')->assertForbidden();
        $admin->roles()->updateExistingPivot($role->id, ['status' => 'ACTIVE']);
        $admin->forceFill(['must_change_password' => true])->save();
        $this->actingAs($admin)->getJson(self::ADMIN.'/sections')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_section_edit_requires_publication_and_leaves_previous_public_version_unchanged(): void
    {
        $this->actingAs($this->actor());
        $section = $this->section();
        $path = self::ADMIN."/sections/{$section->id}";
        $this->patchJson($path, ['description' => 'Texto inicial aprobado.', 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $section->refresh();
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data.sections');
        $this->publish($section);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.sections.0.description', 'Texto inicial aprobado.')
            ->assertJsonMissingPath('data.sections.0.draft')->assertJsonMissingPath('data.sections.0.revision')->assertJsonMissingPath('data.sections.0.updated_by');
        $this->patchJson($path, ['description' => 'Texto nuevo sin publicar.', 'revision' => $section->revision])->assertOk()
            ->assertJsonPath('data.has_unpublished_changes', true)->assertJsonPath('data.published.description', 'Texto inicial aprobado.')
            ->assertJsonPath('data.draft.description', 'Texto nuevo sin publicar.');
        $this->getJson(self::PUBLIC)->assertJsonPath('data.sections.0.description', 'Texto inicial aprobado.');
        $section->refresh();
        $this->publish($section);
        $this->getJson($path)->assertJsonPath('data.has_unpublished_changes', false);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.sections.0.description', 'Texto nuevo sin publicar.');
    }

    public function test_blank_initial_sections_cannot_be_published(): void
    {
        $this->actingAs($this->actor());
        $section = $this->section();
        $this->postJson(self::ADMIN."/sections/{$section->id}/publish", ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertFalse($section->refresh()->is_published);
        $this->assertSame(1, $section->revision);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_faq_is_draft_until_published_and_edited_answer_stays_private(): void
    {
        $this->actingAs($this->actor());
        $faq = $this->faq();
        $path = self::ADMIN."/faqs/{$faq->id}";
        $this->getJson($path)->assertJsonPath('data.published', null)->assertJsonPath('data.has_unpublished_changes', true)->assertJsonMissingPath('data.key');
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data.faqs');
        $this->publish($faq);
        $this->patchJson($path, ['answer' => 'Respuesta nueva pendiente.', 'revision' => $faq->revision])->assertOk();
        $this->getJson(self::PUBLIC)->assertJsonPath('data.faqs.0.answer', 'Respuesta autorizada.');
        $faq->refresh();
        $this->publish($faq);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.faqs.0.answer', 'Respuesta nueva pendiente.');
    }

    public function test_unpublish_deactivates_without_deleting_last_publication_or_draft(): void
    {
        $this->actingAs($this->actor());
        $faq = $this->faq();
        $this->publish($faq);
        $path = self::ADMIN."/faqs/{$faq->id}";
        $this->postJson($path.'/unpublish', ['revision' => $faq->revision])->assertOk()
            ->assertJsonPath('data.is_published', false)->assertJsonPath('data.published.answer', 'Respuesta autorizada.')
            ->assertJsonPath('data.draft.answer', 'Respuesta autorizada.')->assertJsonPath('data.has_unpublished_changes', false);
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data.faqs');
        $this->assertDatabaseCount('portal_information_contents', 7);
        $this->deleteJson($path)->assertStatus(405);
        $faq->refresh();
        $this->publish($faq);
        $this->getJson(self::PUBLIC)->assertJsonCount(1, 'data.faqs');
    }

    public function test_stale_edit_publish_and_unpublish_do_not_overwrite_or_create_audits(): void
    {
        $this->actingAs($this->actor());
        $faq = $this->faq();
        $path = self::ADMIN."/faqs/{$faq->id}";
        $this->patchJson($path, ['answer' => 'Respuesta corregida.', 'revision' => 1])->assertOk();
        $this->patchJson($path, ['answer' => 'Obsoleta.', 'revision' => 1])->assertConflict()->assertJsonPath('code', 'REVISION_CONFLICT')->assertJsonPath('current_revision', 2);
        $this->postJson($path.'/publish', ['revision' => 1])->assertConflict();
        $this->postJson($path.'/unpublish', ['revision' => 1])->assertConflict();
        $this->assertSame('Respuesta corregida.', $faq->refresh()->draft['answer']);
        $this->assertFalse($faq->is_published);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_missing_revision_is_rejected_on_each_mutation(): void
    {
        $this->actingAs($this->actor());
        $section = $this->section();
        $path = self::ADMIN."/sections/{$section->id}";
        $this->patchJson($path, ['description' => 'Texto válido.'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson($path.'/publish')->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson($path.'/unpublish')->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_content_ids_cannot_be_used_across_sections_and_faqs(): void
    {
        $this->actingAs($this->actor());
        $section = $this->section();
        $faq = $this->faq();
        $this->getJson(self::ADMIN."/faqs/{$section->id}")->assertNotFound();
        $this->patchJson(self::ADMIN."/faqs/{$section->id}", ['answer' => 'Respuesta.', 'revision' => 1])->assertNotFound();
        $this->postJson(self::ADMIN."/faqs/{$section->id}/publish", ['revision' => 1])->assertNotFound();
        $this->getJson(self::ADMIN."/sections/{$faq->id}")->assertNotFound();
        $this->postJson(self::ADMIN.'/sections', [])->assertStatus(405);
        $this->getJson(self::ADMIN.'/faqs/999')->assertNotFound();
    }

    public function test_invalid_lengths_sort_order_and_managed_fields_are_rejected(): void
    {
        $this->actingAs($this->actor());
        foreach ([['question' => ''], ['question' => str_repeat('x', 501)], ['answer' => str_repeat('x', 10001)], ['sort_order' => -1], ['is_published' => true], ['published' => ['answer' => 'Inyectada']], ['kind' => 'SECTION']] as $changes) {
            $this->postJson(self::ADMIN.'/faqs', array_replace(['question' => 'Pregunta', 'answer' => 'Respuesta'], $changes))->assertUnprocessable();
        }
        $section = $this->section();
        foreach ([['title' => str_repeat('x', 201)], ['description' => str_repeat('x', 10001)], ['key' => 'replaced'], ['draft' => ['title' => 'Inyectado']], ['question' => 'Otro tipo']] as $changes) {
            $this->patchJson(self::ADMIN."/sections/{$section->id}", $changes + ['revision' => 1])->assertUnprocessable();
        }
        $this->assertDatabaseCount('portal_information_contents', 6);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_section_links_allow_https_internal_and_email_and_require_publication(): void
    {
        $this->actingAs($this->actor());
        $section = $this->section();
        $links = [
            ['label' => 'Sitio institucional', 'url' => 'https://www.umss.edu.bo/'],
            ['label' => 'Información', 'url' => '/informacion'],
            ['label' => 'Contacto', 'url' => 'mailto:contacto@umss.edu.bo'],
        ];
        $this->patchJson(self::ADMIN."/sections/{$section->id}", ['description' => 'Contenido aprobado.', 'links' => $links, 'revision' => 1])->assertOk();
        $section->refresh();
        $this->publish($section);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.sections.0.links', $links);
        $this->patchJson(self::ADMIN."/sections/{$section->id}", ['links' => [], 'revision' => $section->revision])->assertOk();
        $this->getJson(self::PUBLIC)->assertJsonPath('data.sections.0.links', $links);
    }

    public function test_unsafe_or_malformed_links_are_rejected_without_changes(): void
    {
        $this->actingAs($this->actor());
        $section = $this->section();
        foreach (['javascript:alert(1)', 'data:text/html,test', '//evil.example', '/%2Fevil.example', '/%5Cevil.example', 'http://example.com', 'https://user:pass@example.com', 'https://example.com/%0A', 'mailto:not-an-email'] as $url) {
            $this->patchJson(self::ADMIN."/sections/{$section->id}", [
                'revision' => 1,
                'links' => [['label' => 'Enlace', 'url' => $url]],
            ])->assertUnprocessable()->assertJsonValidationErrors('links.0.url');
        }
        $this->patchJson(self::ADMIN."/sections/{$section->id}", ['revision' => 1, 'links' => [['label' => 'Enlace', 'url' => '/informacion', 'html' => '<script>']]])->assertUnprocessable();
        $this->assertSame(1, $section->refresh()->revision);
    }

    public function test_public_order_uses_published_snapshot_not_draft_and_handles_numeric_values(): void
    {
        $this->actingAs($this->actor());
        $later = $this->faq('Pregunta diez', 10);
        $earlier = $this->faq('Pregunta dos', 2);
        $sameOrder = $this->faq('Pregunta dos repetida', 2);
        foreach ([$later, $earlier, $sameOrder] as $faq) {
            $this->publish($faq);
        }
        $this->getJson(self::PUBLIC)->assertJsonPath('data.faqs.0.id', $earlier->id)->assertJsonPath('data.faqs.1.id', $sameOrder->id);
        $this->patchJson(self::ADMIN."/faqs/{$later->id}", ['sort_order' => '0', 'revision' => $later->revision])->assertOk();
        $this->getJson(self::PUBLIC)->assertJsonPath('data.faqs.0.id', $earlier->id);
        $this->getJson(self::ADMIN.'/faqs')->assertJsonPath('data.0.id', $later->id);
        $later->refresh();
        $this->publish($later);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.faqs.0.id', $later->id)->assertJsonPath('data.faqs.0.sort_order', 0);
    }

    public function test_administrative_search_filters_and_pagination_do_not_mix_content_types(): void
    {
        $this->actingAs($this->actor());
        $first = $this->faq('Consulta 100%', 1);
        $second = $this->faq('Segunda consulta', 2);
        $this->publish($first);
        $this->getJson(self::ADMIN.'/faqs?per_page=1&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $second->id);
        $this->getJson(self::ADMIN.'/faqs?search=100%25')->assertJsonCount(1, 'data');
        $this->getJson(self::ADMIN.'/faqs?search=CONSULTA&is_published=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson(self::ADMIN.'/faqs?is_published=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);
        $this->getJson(self::ADMIN.'/sections')->assertJsonPath('meta.total', 6);
        $this->getJson(self::ADMIN.'/faqs?per_page=101')->assertUnprocessable();
        $this->getJson(self::ADMIN.'/faqs?page=0')->assertUnprocessable();
    }

    public function test_public_reads_leave_previous_modules_and_audit_unchanged(): void
    {
        $this->actingAs($this->actor());
        $faq = $this->faq();
        $this->publish($faq);
        $tables = ['portal_information_contents', 'portal_events', 'portal_news', 'audit_logs', 'exams', 'exam_eligibilities', 'exam_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(1, 'data.faqs');
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
    }

    public function test_audit_failure_rolls_back_publication_and_preserves_previous_public_content(): void
    {
        $this->actingAs($this->actor());
        $faq = $this->faq();
        $this->publish($faq);
        $this->patchJson(self::ADMIN."/faqs/{$faq->id}", ['answer' => 'Nueva sin publicar.', 'revision' => $faq->revision])->assertOk();
        $faq->refresh();
        $revision = $faq->revision;
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson(self::ADMIN."/faqs/{$faq->id}/publish", ['revision' => $revision])->assertStatus(500);
        $this->assertSame($revision, $faq->refresh()->revision);
        $this->assertSame('Nueva sin publicar.', $faq->draft['answer']);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.faqs.0.answer', 'Respuesta autorizada.');
        $this->assertDatabaseCount('audit_logs', 3);
    }
}
