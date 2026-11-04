<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\PortalService;
use App\Models\PortalServiceCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Portal\PortalServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PortalServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin/portal/services';

    private const PUBLIC = '/api/v1/portal/services';

    private function actor(string $role = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'WebSISS', 'description' => 'Información estudiantil UMSS.',
            'icon' => 'academic', 'url' => 'https://websiss.umss.edu.bo/', 'display_order' => 1], $changes);
    }

    private function service(bool $active = false, array $changes = []): PortalService
    {
        $this->actingAs($this->actor());
        $id = $this->postJson(self::ADMIN, $this->payload($changes))->assertCreated()->json('data.id');
        if ($active) {
            $this->patchJson(self::ADMIN.'/'.$id.'/status', ['status' => 'ACTIVE', 'revision' => 1])->assertOk();
        }

        return PortalService::findOrFail($id);
    }

    public function test_public_empty_catalog_is_anonymous_paginated_and_read_only(): void
    {
        $this->getJson(self::PUBLIC.'?search=&status=')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC.'/999')->assertNotFound();
        $this->assertDatabaseCount('portal_services', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_every_admin_operation_requires_authentication_and_active_administrator(): void
    {
        $routes = [['GET', self::ADMIN], ['POST', self::ADMIN], ['GET', self::ADMIN.'/999'],
            ['PATCH', self::ADMIN.'/999'], ['PATCH', self::ADMIN.'/999/status'], ['GET', self::ADMIN.'/999/history']];
        foreach ($routes as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actingAs($this->actor($role));
            foreach ($routes as [$method, $path]) {
                $this->json($method, $path)->assertForbidden();
            }
        }
        $admin = $this->actor();
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertUnauthorized();
        $admin = $this->actor();
        $admin->roles()->updateExistingPivot($admin->roles()->first()->id, ['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden();
        $admin = $this->actor();
        $admin->forceFill(['must_change_password' => true])->save();
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_creation_and_full_lifecycle_use_external_contract_without_categories_or_login_hints(): void
    {
        $service = $this->service();
        $this->getJson(self::ADMIN.'/'.$service->id)->assertOk()->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.icon', 'academic')->assertJsonPath('data.url', 'https://websiss.umss.edu.bo/')->assertJsonPath('data.display_order', 1)
            ->assertJsonPath('data.needs_review', false)->assertJsonMissingPath('data.destination')->assertJsonMissingPath('data.requires_auth');
        $this->assertNull($service->category_id);
        $this->getJson(self::PUBLIC)->assertJsonPath('meta.total', 0);
        $this->patchJson(self::ADMIN.'/'.$service->id.'/status', ['status' => 'ACTIVE', 'revision' => 1])->assertOk();
        auth()->forgetGuards();
        $this->getJson(self::PUBLIC.'/'.$service->id)->assertOk()->assertExactJson(['data' => [
            'id' => $service->id, 'name' => 'WebSISS', 'description' => 'Información estudiantil UMSS.',
            'icon' => 'academic', 'url' => 'https://websiss.umss.edu.bo/', 'display_order' => 1]]);
        $this->actingAs(User::find($service->created_by));
        $this->patchJson(self::ADMIN.'/'.$service->id, ['name' => 'Recursos universitarios', 'description' => 'Nueva descripción',
            'icon' => 'book', 'url' => 'https://www.umss.edu.bo/', 'display_order' => 3, 'revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
        $this->getJson(self::PUBLIC.'/'.$service->id)->assertJsonPath('data.name', 'Recursos universitarios')->assertJsonPath('data.url', 'https://www.umss.edu.bo/');
        $this->patchJson(self::ADMIN.'/'.$service->id.'/status', ['status' => 'INACTIVE', 'revision' => 3])->assertOk();
        $this->getJson(self::PUBLIC)->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC.'/'.$service->id)->assertNotFound();
        $this->getJson(self::ADMIN.'/'.$service->id)->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson(self::ADMIN.'/'.$service->id.'/history')->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.new_values.status', 'INACTIVE')->assertJsonPath('data.1.new_values.url', 'https://www.umss.edu.bo/')
            ->assertJsonMissingPath('data.1.new_values.destination_type');
    }

    #[DataProvider('invalidUrls')]
    public function test_unapproved_or_unsafe_urls_are_rejected_on_create_and_edit(string $url): void
    {
        $service = $this->service();
        $this->postJson(self::ADMIN, $this->payload(['url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->patchJson(self::ADMIN.'/'.$service->id, ['url' => $url, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->assertSame('https://websiss.umss.edu.bo/', $service->fresh()->destination);
        $this->assertDatabaseCount('portal_services', 1);
    }

    public static function invalidUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            '', 'invalid', 'http://umss.edu.bo/', 'https://example.com/', 'https://umss.edu.bo.evil.com/', 'https://notumss.edu.bo/',
            '/teacher/exams', '//umss.edu.bo/', 'javascript:alert(1)', 'data:text/html,test',
            'https://user:password@umss.edu.bo/', 'https://umss.edu.bo@evil.com/', 'https://umss.edu.bo:8080/',
            'https://umss.edu.bo/%0d%0aLocation:evil', 'https://umss.edu.bo/%255c%255cevil',
            'https://umss.edu.bo/?access_token=secret', 'https://umss.edu.bo/#jwt=secret', 'https://umss.edu.bo/?%74oken=secret',
            'https://umss.edu.bo/?next%5Bsession_id%5D=secret', 'https://127.0.0.1/',
        ]);
    }

    public function test_allowed_hosts_are_case_insensitive_dot_delimited_and_configurable(): void
    {
        $this->actingAs($this->actor());
        foreach (['https://umss.edu.bo/', 'https://WEBsiss.UMSS.edu.bo/', 'https://www.umss.edu.bo:443/path?lang=es'] as $url) {
            $this->postJson(self::ADMIN, $this->payload(['url' => $url]))->assertCreated();
        }
        config(['portal_services.allow_subdomains' => false]);
        $this->postJson(self::ADMIN, $this->payload())->assertUnprocessable();
        $this->postJson(self::ADMIN, $this->payload(['url' => 'https://umss.edu.bo/']))->assertCreated();
        config(['portal_services.allowed_domains' => []]);
        $this->postJson(self::ADMIN, $this->payload(['url' => 'https://umss.edu.bo/']))->assertUnprocessable();
    }

    public function test_legacy_internal_and_unapproved_external_rows_remain_admin_only_and_can_be_corrected(): void
    {
        $service = $this->service(true);
        $service->forceFill(['destination_type' => 'INTERNAL', 'destination' => '/teacher/exams'])->save();
        $this->getJson(self::PUBLIC)->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC.'/'.$service->id)->assertNotFound();
        $this->getJson(self::ADMIN.'/'.$service->id)->assertJsonPath('data.needs_review', true)->assertJsonPath('data.validation_issues.0', 'url');
        $this->patchJson(self::ADMIN.'/'.$service->id.'/status', ['status' => 'ACTIVE', 'revision' => 2])->assertUnprocessable();
        $this->patchJson(self::ADMIN.'/'.$service->id, ['url' => 'https://umss.edu.bo/', 'revision' => 2])->assertOk()->assertJsonPath('data.needs_review', false);
        $this->getJson(self::PUBLIC)->assertJsonPath('meta.total', 1);
        $service->refresh()->forceFill(['destination' => 'https://unrelated.example/'])->save();
        $this->getJson(self::PUBLIC)->assertJsonPath('meta.total', 0);
        $this->patchJson(self::ADMIN.'/'.$service->id.'/status', ['status' => 'INACTIVE', 'revision' => 3])->assertOk();
    }

    public function test_allowlist_changes_hide_existing_links_immediately_without_deleting_or_losing_history(): void
    {
        $service = $this->service(true);
        config(['portal_services.allowed_domains' => ['another.example']]);
        $this->getJson(self::PUBLIC)->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC.'/'.$service->id)->assertNotFound();
        $this->getJson(self::ADMIN.'/'.$service->id)->assertOk()->assertJsonPath('data.needs_review', true);
        $this->patchJson(self::ADMIN.'/'.$service->id.'/status', ['status' => 'ACTIVE', 'revision' => 2])->assertUnprocessable();
        $this->getJson(self::ADMIN.'/'.$service->id.'/history')->assertJsonPath('meta.total', 2);
    }

    public function test_name_search_status_order_and_filtered_pagination_are_applied_on_server(): void
    {
        $this->service(true, ['name' => 'Servicio Z', 'description' => 'Nombre buscable no debe coincidir', 'display_order' => 3]);
        $b = $this->service(true, ['name' => 'Servicio B', 'display_order' => 1]);
        $this->service(false, ['name' => 'Oculto', 'display_order' => 0]);
        $this->getJson(self::PUBLIC.'?per_page=1')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $b->id);
        $this->getJson(self::PUBLIC.'?search=Servicio%20Z')->assertJsonPath('meta.total', 1);
        $this->getJson(self::PUBLIC.'?search=buscable')->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC.'?status=INACTIVE')->assertJsonPath('meta.total', 2);
        $this->getJson(self::ADMIN.'?status=INACTIVE')->assertJsonPath('meta.total', 1);
        $this->getJson(self::ADMIN.'?search=&status=')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_missing_fields_plain_text_icon_and_old_contract_are_validated(): void
    {
        $this->actingAs($this->actor());
        foreach (['name', 'description', 'icon', 'url'] as $field) {
            $data = $this->payload();
            unset($data[$field]);
            $this->postJson(self::ADMIN, $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>', 'icon' => '<svg onload=alert(1)>', 'display_order' => -1] as $field => $value) {
            $this->postJson(self::ADMIN, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson(self::ADMIN, $this->payload(['category_id' => 1, 'destination_type' => 'INTERNAL', 'destination' => '/teacher/exams', 'requires_auth' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'destination_type', 'destination', 'requires_auth']);
        $this->getJson('/api/v1/admin/portal/service-options')->assertNotFound();
        $this->getJson('/api/v1/admin/portal/service-categories')->assertNotFound();
        $this->getJson('/api/v1/portal/service-categories')->assertNotFound();
    }

    public function test_revision_conflicts_missing_revision_and_audit_failure_preserve_previous_service(): void
    {
        $service = $this->service(true);
        $this->patchJson(self::ADMIN.'/'.$service->id, ['name' => 'Stale', 'revision' => 1])->assertConflict()->assertJsonPath('code', 'REVISION_CONFLICT');
        $this->patchJson(self::ADMIN.'/'.$service->id.'/status', ['status' => 'INACTIVE', 'revision' => 1])->assertConflict();
        $this->patchJson(self::ADMIN.'/'.$service->id, ['name' => 'Missing revision'])->assertUnprocessable();
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->patchJson(self::ADMIN.'/'.$service->id, ['name' => 'Rollback', 'revision' => 2])->assertStatus(500);
        $this->assertSame('WebSISS', $service->fresh()->name);
        $this->assertSame(2, $service->fresh()->revision);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_public_server_errors_are_controlled_without_exposing_exception_details(): void
    {
        $this->mock(PortalServiceCatalog::class)->shouldReceive('paginate')->andThrow(new RuntimeException('Private database details'));
        $this->getJson(self::PUBLIC)->assertStatus(500)->assertExactJson([
            'code' => 'INTERNAL_ERROR', 'message' => 'No se pudo completar la operación de servicios. Intenta nuevamente.',
        ]);
    }

    public function test_migration_retires_internal_links_preserving_records_and_audit_and_allows_categoryless_creation(): void
    {
        $service = $this->service(true);
        $category = PortalServiceCategory::create(['name' => 'Categoría anterior']);
        $service->forceFill(['category_id' => $category->id, 'destination_type' => 'INTERNAL', 'destination' => '/teacher/exams'])->save();
        $migration = require database_path('migrations/2026_11_03_000001_adapt_portal_services_to_external_links.php');
        $migration->up();
        $this->assertSame('INACTIVE', $service->fresh()->status);
        $this->assertSame(3, $service->fresh()->revision);
        $this->assertSame($category->id, $service->fresh()->category_id);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $service->id, 'action' => 'PORTAL_SERVICE_INTERNAL_RETIRED', 'user_id' => null]);
        $this->assertDatabaseCount('portal_services', 1);
        $migration->up();
        $this->assertSame(1, AuditLog::where('action', 'PORTAL_SERVICE_INTERNAL_RETIRED')->count());
        $this->postJson(self::ADMIN, $this->payload())->assertCreated();
        $this->assertDatabaseHas('portal_services', ['category_id' => null, 'destination_type' => 'EXTERNAL', 'requires_auth' => false]);
    }
}
