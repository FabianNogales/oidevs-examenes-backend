<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\PortalEvent;
use App\Models\PortalEventCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
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
        $this->getJson('/api/v1/portal/event-categories')->assertOk()->assertExactJson(['data' => []]);
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
        $this->assertDatabaseHas('portal_events', ['id' => $event->id, 'starts_at' => '2026-11-05 13:00:00']);
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
        $this->getJson('/api/v1/portal/event-categories')->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/admin/portal/event-categories')->assertExactJson(['data' => []]);
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
}
