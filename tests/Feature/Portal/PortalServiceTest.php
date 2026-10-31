<?php

namespace Tests\Feature\Portal;

use App\Models\PortalService;
use App\Models\PortalServiceCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $category = PortalServiceCategory::firstOrCreate(['name' => 'Categoría de prueba']);

        return array_replace([
            'name' => 'Servicio de prueba',
            'description' => 'Descripción del acceso institucional.',
            'icon_key' => 'link',
            'category_id' => $category->id,
            'destination_type' => 'EXTERNAL',
            'destination' => 'https://www.umss.edu.bo/',
            'requires_auth' => false,
            'sort_order' => 1,
        ], $changes);
    }

    private function service(bool $active = false, array $changes = []): PortalService
    {
        $this->actingAs($this->actor());
        $response = $this->postJson(self::ADMIN, $this->payload($changes))->assertCreated();
        $service = PortalService::findOrFail($response->json('data.id'));
        if ($active) {
            $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'ACTIVE', 'revision' => 1])->assertOk();
        }

        return $service->refresh();
    }

    private function routes(): void
    {
        config(['portal_services.internal_destinations' => [
            ['path' => '/qa-public-service', 'label' => 'Ruta de prueba pública', 'requires_auth' => false],
            ['path' => '/qa-private-service', 'label' => 'Ruta de prueba protegida', 'requires_auth' => true],
        ]]);
    }

    public function test_public_empty_catalog_and_categories_do_not_require_login_or_create_data(): void
    {
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/portal/service-categories')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson(self::PUBLIC.'/999')->assertNotFound();
        $this->assertDatabaseCount('portal_services', 0);
        $this->assertDatabaseCount('portal_service_categories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_every_administrative_route_requires_authentication_and_active_admin_role(): void
    {
        $routes = [
            ['GET', self::ADMIN], ['GET', self::ADMIN.'/999'], ['POST', self::ADMIN],
            ['PATCH', self::ADMIN.'/999'], ['PATCH', self::ADMIN.'/999/status'],
            ['GET', '/api/v1/admin/portal/service-categories'], ['GET', '/api/v1/admin/portal/service-options'],
        ];
        foreach ($routes as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actingAs($this->actor($role));
            foreach ($routes as [$method, $path]) {
                $this->json($method, $path)->assertForbidden();
            }
        }
    }

    public function test_inactive_account_role_assignment_and_first_access_are_blocked(): void
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

    public function test_options_are_backend_owned_and_internal_routes_start_empty(): void
    {
        $this->actingAs($this->actor());
        $this->getJson('/api/v1/admin/portal/service-options')->assertOk()
            ->assertJsonPath('data.internal_destinations', [])
            ->assertJsonPath('data.destination_types', ['INTERNAL', 'EXTERNAL'])
            ->assertJsonPath('data.icon_keys', ['calendar', 'book', 'info', 'link', 'users']);
        $this->postJson(self::ADMIN, $this->payload(['destination_type' => 'INTERNAL', 'destination' => '/estudiante/examenes']))
            ->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->assertDatabaseCount('portal_services', 0);
    }

    public function test_creation_is_inactive_and_activation_exposes_only_public_fields(): void
    {
        $service = $this->service();
        $this->assertSame('INACTIVE', $service->status);
        $this->getJson(self::PUBLIC."/{$service->id}")->assertNotFound();
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data');
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'ACTIVE', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/{$service->id}")->assertOk()
            ->assertJsonPath('data.destination', 'https://www.umss.edu.bo/')->assertJsonPath('data.requires_auth', false)
            ->assertJsonMissingPath('data.revision')->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.updated_by');
        $this->getJson(self::PUBLIC)->assertJsonCount(1, 'data');
    }

    public function test_deactivation_preserves_record_and_audit(): void
    {
        $service = $this->service(true);
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'INACTIVE', 'revision' => $service->revision])->assertOk();
        $this->getJson(self::PUBLIC."/{$service->id}")->assertNotFound();
        $this->getJson(self::ADMIN."/{$service->id}")->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->assertDatabaseCount('portal_services', 1);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->deleteJson(self::ADMIN."/{$service->id}")->assertStatus(405);
    }

    public function test_internal_destination_must_match_options_and_keep_required_login(): void
    {
        $this->routes();
        $this->actingAs($this->actor());
        $this->getJson('/api/v1/admin/portal/service-options')->assertJsonPath('data.internal_destinations.1.path', '/qa-private-service');
        $data = $this->payload(['destination_type' => 'INTERNAL', 'destination' => '/qa-private-service']);
        $this->postJson(self::ADMIN, $data)->assertUnprocessable()->assertJsonValidationErrors('requires_auth');
        unset($data['requires_auth']);
        $this->postJson(self::ADMIN, $data)->assertCreated()->assertJsonPath('data.requires_auth', true);
        $this->postJson(self::ADMIN, $this->payload(['destination_type' => 'INTERNAL', 'destination' => '/qa-public-service']))->assertCreated();
        foreach (['/qa-private-service?next=evil', '/qa-private-service#x', '//evil.example', '/%2fevil.example', '/qa-unknown'] as $path) {
            $this->postJson(self::ADMIN, $this->payload(['destination_type' => 'INTERNAL', 'destination' => $path, 'requires_auth' => true]))
                ->assertUnprocessable()->assertJsonValidationErrors('destination');
        }
    }

    public function test_external_destinations_reject_insecure_schemes_credentials_and_obfuscated_urls(): void
    {
        $this->actingAs($this->actor());
        foreach (['javascript:alert(1)', 'data:text/html,x', 'http://example.com', '//evil.example', '/local', 'mailto:person@example.com', 'https://user:pass@example.com', 'https://example.com/%0A', 'https://example.com/%5C', 'https:/missing-host'] as $url) {
            $this->postJson(self::ADMIN, $this->payload(['destination' => $url]))
                ->assertUnprocessable()->assertJsonValidationErrors('destination');
        }
        $this->assertDatabaseCount('portal_services', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_icon_keys_are_validated_and_raw_html_is_not_accepted(): void
    {
        $this->actingAs($this->actor());
        foreach (['unknown-icon', '<svg onload="bad()">', 'javascript:bad'] as $icon) {
            $this->postJson(self::ADMIN, $this->payload(['icon_key' => $icon]))->assertUnprocessable()->assertJsonValidationErrors('icon_key');
        }
    }

    public function test_partial_edits_revalidate_complete_destination_and_require_revision(): void
    {
        $service = $this->service();
        $path = self::ADMIN."/{$service->id}";
        $this->patchJson($path, ['name' => 'Nuevo nombre'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->patchJson($path, ['destination_type' => 'INTERNAL', 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->patchJson($path, ['name' => 'Nuevo nombre', 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame('https://www.umss.edu.bo/', $service->refresh()->destination);
    }

    public function test_stale_edit_and_status_changes_do_not_overwrite_or_add_audits(): void
    {
        $service = $this->service();
        $path = self::ADMIN."/{$service->id}";
        $this->patchJson($path, ['name' => 'Actualizado', 'revision' => 1])->assertOk();
        $this->patchJson($path, ['name' => 'Obsoleto', 'revision' => 1])->assertConflict()->assertJsonPath('code', 'REVISION_CONFLICT')->assertJsonPath('current_revision', 2);
        $this->patchJson($path.'/status', ['status' => 'ACTIVE', 'revision' => 1])->assertConflict();
        $this->assertSame('Actualizado', $service->refresh()->name);
        $this->assertSame('INACTIVE', $service->status);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_categories_are_active_only_and_inactive_categories_block_activation(): void
    {
        $service = $this->service();
        $service->category->update(['is_active' => false]);
        $this->getJson('/api/v1/portal/service-categories')->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/admin/portal/service-categories')->assertExactJson(['data' => []]);
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'ACTIVE', 'revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson(self::ADMIN, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_removing_an_internal_route_hides_it_and_prevents_reactivation_but_allows_deactivation(): void
    {
        $this->routes();
        $service = $this->service(true, ['destination_type' => 'INTERNAL', 'destination' => '/qa-private-service', 'requires_auth' => true]);
        $this->getJson(self::PUBLIC."/{$service->id}")->assertOk();
        config(['portal_services.internal_destinations' => []]);
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson(self::PUBLIC."/{$service->id}")->assertNotFound();
        $this->getJson(self::ADMIN."/{$service->id}")->assertOk();
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'ACTIVE', 'revision' => $service->revision])->assertUnprocessable();
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'INACTIVE', 'revision' => $service->revision])->assertOk();
    }

    public function test_search_filters_numeric_order_and_pagination_do_not_expose_inactive_services(): void
    {
        $later = $this->service(true, ['name' => 'Servicio 100%', 'sort_order' => 10]);
        $earlier = $this->service(true, ['sort_order' => 2]);
        $sameOrder = $this->service(true, ['sort_order' => 2]);
        $inactive = $this->service();
        $this->getJson(self::PUBLIC.'?per_page=1')->assertJsonPath('data.0.id', $earlier->id)->assertJsonPath('meta.total', 3);
        $this->getJson(self::PUBLIC.'?per_page=1&page=2')->assertJsonPath('data.0.id', $sameOrder->id);
        $this->getJson(self::PUBLIC.'?search=100%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $later->id);
        $this->getJson(self::PUBLIC.'?search=SERVICIO&status=INACTIVE')->assertJsonCount(3, 'data');
        $this->getJson(self::PUBLIC.'?category_id=999')->assertJsonCount(0, 'data');
        $this->getJson(self::ADMIN.'?status=INACTIVE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson(self::PUBLIC.'?page=0')->assertUnprocessable();
        $this->getJson(self::PUBLIC.'?per_page=101')->assertUnprocessable();
    }

    public function test_lengths_invalid_categories_and_managed_fields_are_rejected(): void
    {
        $this->actingAs($this->actor());
        foreach ([['name' => ''], ['name' => str_repeat('x', 201)], ['description' => str_repeat('x', 2001)], ['destination' => str_repeat('x', 2049)], ['category_id' => 999], ['sort_order' => -1], ['status' => 'ACTIVE'], ['created_by' => 999], ['destination_type' => 'SCRIPT']] as $changes) {
            $this->postJson(self::ADMIN, $this->payload($changes))->assertUnprocessable();
        }
        $this->assertDatabaseCount('portal_services', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_service_links_never_change_role_or_grant_access_to_protected_endpoints(): void
    {
        $this->routes();
        $service = $this->service(true, ['destination_type' => 'INTERNAL', 'destination' => '/qa-private-service', 'requires_auth' => true]);
        $student = $this->actor('ESTUDIANTE');
        $before = $student->activeRoles()->pluck('roles.name')->all();
        $this->actingAs($student)->getJson(self::PUBLIC."/{$service->id}")->assertOk()->assertJsonPath('data.requires_auth', true);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/portal/service-options')->assertForbidden();
        $this->assertSame($before, $student->activeRoles()->pluck('roles.name')->all());
    }

    public function test_public_reads_leave_existing_modules_and_audit_unchanged(): void
    {
        $service = $this->service(true);
        $tables = ['portal_services', 'portal_events', 'portal_news', 'portal_information_contents', 'audit_logs', 'exams', 'exam_eligibilities', 'exam_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC)->assertOk();
        $this->getJson(self::PUBLIC."/{$service->id}")->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
    }

    public function test_audit_failure_rolls_back_creation(): void
    {
        $this->actingAs($this->actor());
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson(self::ADMIN, $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('portal_services', 0);
    }

    public function test_missing_resources_and_invalid_status_are_rejected(): void
    {
        $service = $this->service();
        $this->getJson(self::ADMIN.'/999')->assertNotFound();
        $this->patchJson(self::ADMIN.'/999', ['name' => 'Nuevo', 'revision' => 1])->assertNotFound();
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'PUBLISHED', 'revision' => 1])->assertUnprocessable();
        $this->patchJson(self::ADMIN."/{$service->id}/status", ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_public_login_hint_respects_current_backend_destination_requirements(): void
    {
        $this->routes();
        $service = $this->service(true, ['destination_type' => 'INTERNAL', 'destination' => '/qa-public-service', 'requires_auth' => false]);
        $this->getJson(self::PUBLIC."/{$service->id}")->assertJsonPath('data.requires_auth', false);
        config(['portal_services.internal_destinations' => [
            ['path' => '/qa-public-service', 'label' => 'Ruta de prueba ahora protegida', 'requires_auth' => true],
        ]]);
        $this->getJson(self::PUBLIC."/{$service->id}")->assertJsonPath('data.requires_auth', true);
        $this->getJson(self::ADMIN."/{$service->id}")->assertJsonPath('data.requires_auth', false);
        $this->patchJson(self::ADMIN."/{$service->id}", ['requires_auth' => false, 'revision' => $service->revision])
            ->assertUnprocessable()->assertJsonValidationErrors('requires_auth');
    }
}
