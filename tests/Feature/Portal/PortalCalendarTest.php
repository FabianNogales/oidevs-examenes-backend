<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\PortalEvent;
use App\Models\PortalEventCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Database\Seeders\PortalCalendarDemoCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin/portal/events';

    private const PUBLIC = '/api/v1/portal/events';

    private function actor(string $role = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $record = Role::firstOrCreate(['name' => $role], ['status' => 'ACTIVE']);
        $user->roles()->attach($record->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    public function test_initialization_is_repeatable_and_preserves_existing_category_state(): void
    {
        $base = PortalEventCategory::where('name', 'Actividades académicas')->firstOrFail();
        $this->assertTrue($base->is_active);
        $migration = require database_path('migrations/2026_11_04_000002_initialize_portal_calendar_category.php');
        $migration->up();
        $this->seed(PortalCalendarDemoCategorySeeder::class);
        $this->seed(PortalCalendarDemoCategorySeeder::class);
        $this->assertSame(1, PortalEventCategory::where('name', 'Actividades académicas')->count());
        $this->assertSame($base->id, PortalEventCategory::where('name', 'Actividades académicas')->value('id'));
        $base->update(['is_active' => false]);
        $migration->up();
        $this->seed(PortalCalendarDemoCategorySeeder::class);
        $migration->down();
        $this->assertFalse($base->fresh()->is_active);
        $this->assertDatabaseCount('portal_events', 0);
    }

    public function test_inactive_category_preserves_published_history_and_category_identity(): void
    {
        $category = PortalEventCategory::where('name', 'Actividades académicas')->firstOrFail();
        $event = $this->event('PUBLISHED', ['category_id' => $category->id]);
        $this->patchJson(self::ADMIN.'/'.$event->id, ['revision' => 2, 'title' => 'Título actualizado'])->assertOk()
            ->assertJsonPath('data.category.id', $category->id);
        $before = $event->fresh()->getAttributes();
        $category->update(['is_active' => false]);
        $this->getJson(self::PUBLIC.'/'.$event->id)->assertOk()->assertJsonPath('data.category.id', $category->id);
        $this->getJson(self::ADMIN.'/'.$event->id)->assertOk()->assertJsonPath('data.category.id', $category->id);
        $this->getJson(self::PUBLIC)->assertJsonPath('data.0.category.id', $category->id);
        $this->postJson(self::ADMIN, $this->payload(['category_id' => $category->id]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertSame($before, $event->fresh()->getAttributes());
    }

    private function payload(array $changes = []): array
    {
        $category = PortalEventCategory::firstOrCreate(['name' => 'Categoría de prueba']);

        return array_replace([
            'title' => 'Jornada institucional',
            'description' => 'Descripción pública de la jornada.',
            'starts_at' => '2026-11-05T09:00:00-04:00',
            'ends_at' => '2026-11-05T11:00:00-04:00',
            'category_id' => $category->id,
            'all_day' => false,
            'location' => 'Auditorio',
        ], $changes);
    }

    private function event(string $status = 'DRAFT', array $changes = []): PortalEvent
    {
        $admin = $this->actor();
        $response = $this->actingAs($admin)->postJson(self::ADMIN, $this->payload($changes))->assertCreated();
        $event = PortalEvent::findOrFail($response->json('data.id'));
        if ($status !== 'DRAFT') {
            $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => $status, 'revision' => 1])->assertOk();
        }

        return $event->refresh();
    }

    public function test_guests_can_consult_empty_calendar_and_categories_without_writing_data(): void
    {
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/portal/event-categories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Actividades académicas');
        $this->assertDatabaseCount('portal_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_administrative_routes_require_authentication_and_active_administrator_role(): void
    {
        $paths = [self::ADMIN, '/api/v1/admin/portal/event-categories', self::ADMIN.'/999'];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->postJson(self::ADMIN, [])->assertUnauthorized();
        $this->patchJson(self::ADMIN.'/999', [])->assertUnauthorized();
        $this->patchJson(self::ADMIN.'/999/status', [])->assertUnauthorized();
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->actingAs($this->actor($role));
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
            $this->postJson(self::ADMIN, [])->assertForbidden();
            $this->patchJson(self::ADMIN.'/999', [])->assertForbidden();
            $this->patchJson(self::ADMIN.'/999/status', [])->assertForbidden();
        }
        $admin = $this->actor();
        $admin->roles()->updateExistingPivot($admin->roles()->first()->id, ['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden();
    }

    public function test_inactive_account_inactive_role_and_first_access_are_rejected(): void
    {
        $admin = $this->actor();
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertUnauthorized();
        $admin = $this->actor();
        $role = $admin->roles()->first();
        $role->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden();
        $role->update(['status' => 'ACTIVE']);
        $admin->forceFill(['must_change_password' => true])->save();
        $this->actingAs($admin)->getJson(self::ADMIN)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_creation_is_draft_and_only_explicit_publication_exposes_the_event(): void
    {
        $event = $this->event();
        $this->getJson(self::PUBLIC)->assertJsonCount(0, 'data');
        $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
        $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => 'PUBLISHED', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.status', 'PUBLISHED');
        $this->getJson(self::PUBLIC."/{$event->id}")->assertOk()
            ->assertJsonPath('data.starts_at', '2026-11-05T09:00:00-04:00')
            ->assertJsonPath('data.ends_at', '2026-11-05T11:00:00-04:00')
            ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.updated_by')->assertJsonMissingPath('data.revision');
        $this->getJson(self::PUBLIC)->assertJsonCount(1, 'data');
        $this->assertTrue($event->fresh()->starts_at->equalTo(CarbonImmutable::parse('2026-11-05T13:00:00+00:00')));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'PortalEvent', 'entity_id' => $event->id, 'action' => 'PORTAL_EVENT_CREATED']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $event->id, 'action' => 'PORTAL_EVENT_STATUS_CHANGED']);
    }

    public function test_unpublishing_and_cancellation_hide_details_but_preserve_history(): void
    {
        $event = $this->event('PUBLISHED');
        $publishedAt = $event->published_at->toIso8601String();
        foreach (['DRAFT', 'PUBLISHED', 'CANCELLED'] as $status) {
            $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => $status, 'revision' => $event->revision])->assertOk();
            $event->refresh();
            $this->assertSame($publishedAt, $event->published_at->toIso8601String());
            $response = $this->getJson(self::PUBLIC."/{$event->id}");
            $status === 'PUBLISHED' ? $response->assertOk() : $response->assertNotFound();
        }
        $this->getJson(self::ADMIN."/{$event->id}")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertDatabaseCount('portal_events', 1);
        $this->assertSame(5, AuditLog::where('entity_type', 'PortalEvent')->count());
        $this->deleteJson(self::ADMIN."/{$event->id}")->assertStatus(405);
    }

    public function test_stale_edit_and_status_change_do_not_overwrite_or_create_audit(): void
    {
        $event = $this->event();
        $this->patchJson(self::ADMIN."/{$event->id}", ['title' => 'Actualizado', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson(self::ADMIN."/{$event->id}", ['title' => 'Obsoleto', 'revision' => 1])
            ->assertConflict()->assertJsonPath('code', 'REVISION_CONFLICT')->assertJsonPath('current_revision', 2);
        $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => 'PUBLISHED', 'revision' => 1])->assertConflict();
        $this->assertSame('Actualizado', $event->refresh()->title);
        $this->assertSame('DRAFT', $event->status);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_invalid_dates_categories_lengths_and_mass_assignment_are_rejected(): void
    {
        $this->actingAs($this->actor());
        foreach ([
            ['starts_at' => '2026-02-30T09:00:00-04:00'],
            ['starts_at' => '2026-11-05 09:00:00'],
            ['ends_at' => '2026-11-05T09:00:00-04:00'],
            ['ends_at' => '2026-11-05T08:59:59-04:00'],
            ['category_id' => 999],
            ['title' => str_repeat('x', 201)],
            ['description' => ''],
            ['status' => 'PUBLISHED'],
            ['created_by' => 999],
        ] as $changes) {
            $this->postJson(self::ADMIN, $this->payload($changes))->assertUnprocessable();
        }
        PortalEventCategory::query()->update(['is_active' => false]);
        $this->postJson(self::ADMIN, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertDatabaseCount('portal_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_partial_edits_revalidate_interval_and_require_revision(): void
    {
        $event = $this->event();
        $this->patchJson(self::ADMIN."/{$event->id}", ['title' => 'Nuevo'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->patchJson(self::ADMIN."/{$event->id}", ['starts_at' => '2026-11-05T12:00:00-04:00', 'revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => 'ACTIVE', 'revision' => 1])->assertUnprocessable();
        $this->assertSame(1, $event->refresh()->revision);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_calendar_range_includes_overlaps_and_uses_bolivian_midnight_with_exclusive_end(): void
    {
        $overlap = $this->event('PUBLISHED', ['starts_at' => '2026-10-31T23:00:00-04:00', 'ends_at' => '2026-11-01T01:00:00-04:00']);
        $this->event('PUBLISHED', ['starts_at' => '2026-10-31T20:00:00-04:00', 'ends_at' => '2026-11-01T00:00:00-04:00']);
        $this->event('PUBLISHED', ['starts_at' => '2026-11-02T00:00:00-04:00', 'ends_at' => '2026-11-02T01:00:00-04:00']);
        $this->event('DRAFT', ['starts_at' => '2026-11-01T10:00:00-04:00', 'ends_at' => '2026-11-01T11:00:00-04:00']);
        $this->getJson(self::PUBLIC.'?from=2026-11-01&to=2026-11-01')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $overlap->id);
        foreach (['from=2026-11-01', 'from=2026-11-02&to=2026-11-01', 'from=2026-01-01&to=2027-01-02', 'from=2026-02-30&to=2026-03-01'] as $query) {
            $this->getJson(self::PUBLIC.'?'.$query)->assertUnprocessable();
        }
    }

    public function test_all_day_events_require_local_midnights_and_keep_exclusive_end(): void
    {
        $event = $this->event('PUBLISHED', ['all_day' => true, 'starts_at' => '2026-11-05T00:00:00-04:00', 'ends_at' => '2026-11-07T00:00:00-04:00']);
        $this->getJson(self::PUBLIC.'?from=2026-11-06&to=2026-11-06')->assertJsonCount(1, 'data')->assertJsonPath('data.0.all_day', true);
        $this->getJson(self::PUBLIC.'?from=2026-11-07&to=2026-11-07')->assertJsonCount(0, 'data');
        $this->patchJson(self::ADMIN."/{$event->id}", ['ends_at' => '2026-11-07T01:00:00-04:00', 'revision' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson(self::ADMIN, $this->payload(['all_day' => true]))->assertUnprocessable();
    }

    public function test_search_categories_pagination_and_status_do_not_expose_private_events(): void
    {
        $event = $this->event('PUBLISHED', ['title' => 'Jornada 100%']);
        $this->event('PUBLISHED', ['title' => 'Segunda jornada']);
        $private = $this->event('DRAFT', ['title' => 'Jornada reservada']);
        $this->getJson(self::PUBLIC.'?search=JORNADA&per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);
        $this->getJson(self::PUBLIC.'?search=100%25')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event->id);
        $this->getJson(self::PUBLIC.'?status=DRAFT')->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $private->id, 'title' => $private->title]);
        $this->getJson(self::PUBLIC.'?category_id=999')->assertJsonCount(0, 'data');
        $this->getJson(self::ADMIN.'?status=DRAFT')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $private->id);
        $this->getJson(self::PUBLIC.'?per_page=101')->assertUnprocessable();
        $this->getJson(self::PUBLIC.'?page=0')->assertUnprocessable();
    }

    public function test_inactive_categories_are_not_offered_and_block_publication(): void
    {
        $event = $this->event();
        $event->category->update(['is_active' => false]);
        $this->getJson('/api/v1/portal/event-categories')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Actividades académicas');
        $this->getJson('/api/v1/admin/portal/event-categories')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Actividades académicas');
        $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => 'PUBLISHED', 'revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_missing_resources_return_404_and_public_reads_leave_all_records_unchanged(): void
    {
        $event = $this->event('PUBLISHED');
        $tables = ['portal_events', 'audit_logs', 'exams', 'exam_eligibilities', 'exam_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->getJson(self::PUBLIC)->assertOk();
        $this->getJson(self::PUBLIC."/{$event->id}")->assertOk();
        $this->getJson(self::PUBLIC.'/999')->assertNotFound();
        $this->getJson(self::ADMIN.'/999')->assertNotFound();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
    }

    public function test_failed_audit_rolls_back_creation(): void
    {
        $this->actingAs($this->actor());
        $audit = $this->mock(AuditLogService::class);
        $audit->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson(self::ADMIN, $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('portal_events', 0);
    }

    public function test_instants_are_independent_of_application_timezone_and_input_offset(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/La_Paz', 'America/Chicago'] as $timezone) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
                $event = $this->event('PUBLISHED', [
                    'starts_at' => '2026-11-05T13:00:00+00:00',
                    'ends_at' => '2026-11-05T15:00:00+00:00',
                ]);
                $this->getJson(self::PUBLIC."/{$event->id}")
                    ->assertOk()->assertJsonPath('data.starts_at', '2026-11-05T09:00:00-04:00');
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_default_visibility_is_public_and_public_response_contract_is_unchanged(): void
    {
        $event = $this->event('PUBLISHED');
        $this->assertSame('PUBLIC', $event->visibility);
        $this->getJson(self::ADMIN."/{$event->id}")->assertOk()->assertJsonPath('data.visibility', 'PUBLIC');
        $this->getJson(self::PUBLIC."/{$event->id}")->assertOk()->assertJsonMissingPath('data.visibility');
    }

    public function test_private_published_event_is_admin_only_even_when_its_id_or_filters_are_known(): void
    {
        $event = $this->event('PUBLISHED', ['visibility' => 'PRIVATE']);
        $this->getJson(self::ADMIN."/{$event->id}")->assertOk()->assertJsonPath('data.visibility', 'PRIVATE');
        $this->getJson(self::ADMIN."/{$event->id}/history")->assertOk()->assertJsonPath('data.0.new_values.visibility', 'PRIVATE');
        foreach (['DOCENTE', 'ESTUDIANTE', 'ADMINISTRADOR'] as $role) {
            $this->actingAs($this->actor($role));
            $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
            $this->getJson(self::PUBLIC.'?visibility=PRIVATE&status=PUBLISHED&search=Jornada')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
            if ($role !== 'ADMINISTRADOR') {
                $this->getJson(self::ADMIN."/{$event->id}")->assertForbidden();
                $this->getJson(self::ADMIN."/{$event->id}/history")->assertForbidden();
            }
        }
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
        $this->getJson(self::PUBLIC.'?from=2026-11-05&to=2026-11-05')->assertJsonCount(0, 'data');
    }

    public function test_administration_can_filter_visibility_without_affecting_public_totals(): void
    {
        $public = $this->event('PUBLISHED');
        $private = $this->event('PUBLISHED', ['visibility' => 'PRIVATE']);
        $this->event('DRAFT', ['visibility' => 'PRIVATE']);
        $this->getJson(self::ADMIN.'?visibility=PRIVATE&status=PUBLISHED')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $private->id);
        $this->getJson(self::ADMIN.'?visibility=PRIVATE')->assertJsonPath('meta.total', 2);
        $this->getJson(self::PUBLIC.'?visibility=PRIVATE&per_page=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $public->id);
    }

    public function test_visibility_changes_are_immediate_revision_controlled_and_audited(): void
    {
        $event = $this->event('PUBLISHED');
        $path = self::ADMIN."/{$event->id}";
        $this->patchJson($path, ['visibility' => 'PRIVATE', 'revision' => 2])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.status', 'PUBLISHED');
        $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
        $this->patchJson($path, ['visibility' => 'PUBLIC', 'revision' => 2])->assertConflict();
        $this->assertSame('PRIVATE', $event->refresh()->visibility);
        $this->getJson($path.'/history')->assertJsonPath('data.0.old_values.visibility', 'PUBLIC')->assertJsonPath('data.0.new_values.visibility', 'PRIVATE');
        $this->patchJson($path, ['visibility' => 'PUBLIC', 'revision' => 3])->assertOk();
        $this->getJson(self::PUBLIC."/{$event->id}")->assertOk();
        $this->assertDatabaseCount('audit_logs', 4);
    }

    public function test_privacy_is_independent_of_draft_publication_and_cancellation(): void
    {
        $event = $this->event('DRAFT', ['visibility' => 'PRIVATE']);
        foreach (['PUBLISHED', 'CANCELLED', 'DRAFT'] as $status) {
            $this->patchJson(self::ADMIN."/{$event->id}/status", ['status' => $status, 'revision' => $event->revision])
                ->assertOk()->assertJsonPath('data.visibility', 'PRIVATE');
            $event->refresh();
            $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
        }
        $this->patchJson(self::ADMIN."/{$event->id}", ['visibility' => 'PUBLIC', 'revision' => $event->revision])->assertOk();
        $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
    }

    public function test_invalid_visibility_is_rejected_on_create_edit_and_filters(): void
    {
        $event = $this->event();
        foreach (['PERSONAL', 'ADMIN', '', null] as $value) {
            $this->postJson(self::ADMIN, $this->payload(['visibility' => $value]))->assertUnprocessable()->assertJsonValidationErrors('visibility');
            $this->patchJson(self::ADMIN."/{$event->id}", ['visibility' => $value, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('visibility');
        }
        $this->getJson(self::ADMIN.'?visibility=PERSONAL')->assertUnprocessable();
        $this->assertSame(1, $event->refresh()->revision);
    }

    public function test_edit_without_visibility_does_not_make_a_private_event_public(): void
    {
        $event = $this->event('PUBLISHED', ['visibility' => 'PRIVATE']);
        $this->patchJson(self::ADMIN."/{$event->id}", ['title' => 'Título corregido', 'revision' => $event->revision])
            ->assertOk()->assertJsonPath('data.visibility', 'PRIVATE');
        $this->getJson(self::PUBLIC."/{$event->id}")->assertNotFound();
    }

    public function test_visibility_migration_preserves_preexisting_events_as_public(): void
    {
        $event = $this->event('PUBLISHED');
        $migration = require database_path('migrations/2026_11_01_000001_add_visibility_to_portal_events.php');
        $migration->down();
        $before = DB::table('portal_events')->where('id', $event->id)->first();
        $migration->up();
        $after = DB::table('portal_events')->where('id', $event->id)->first();
        $this->assertSame('PUBLIC', $after->visibility);
        unset($after->visibility);
        $this->assertEquals($before, $after);
        $this->getJson(self::PUBLIC."/{$event->id}")->assertOk();
    }

    public function test_failed_visibility_change_preserves_the_previous_public_event(): void
    {
        $event = $this->event('PUBLISHED');
        $revision = $event->revision;
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->patchJson(self::ADMIN."/{$event->id}", ['visibility' => 'PRIVATE', 'revision' => $revision])->assertStatus(500);
        $this->assertSame('PUBLIC', $event->refresh()->visibility);
        $this->assertSame($revision, $event->revision);
        $this->getJson(self::PUBLIC."/{$event->id}")->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);
    }
}
