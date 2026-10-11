<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AcademicTermWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private const BASE = '/api/v1/admin/academic-terms';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
    }

    public function test_create_inactive_and_active_returns_resource_and_atomic_audit(): void
    {
        foreach (['INACTIVE', 'ACTIVE'] as $index => $status) {
            $year = 2027 + $index;
            $response = $this->postJson(self::BASE, $this->payload(['name' => "  Gestión $year  ", 'start_date' => "$year-01-01", 'end_date' => "$year-06-30", 'status' => $status]))
                ->assertCreated()->assertJsonPath('data.name', "Gestión $year")->assertJsonPath('data.start_date', "$year-01-01")->assertJsonPath('data.status', $status);
            $audit = AuditLog::where('entity_id', $response->json('data.id'))->where('action', 'ACADEMIC_TERM_CREATED')->firstOrFail();
            $this->assertSame('ACADEMIC_TERM', $audit->entity_type);
            $this->assertSame($this->admin->id, $audit->user_id);
            $this->assertNull($audit->old_values);
            $this->assertSame($response->json('data.start_date'), $audit->new_values['start_date']);
        }
        $this->assertDatabaseCount('academic_terms', 2);
    }

    public function test_edit_preserves_status_and_no_op_has_no_new_audit_or_write(): void
    {
        $term = AcademicTerm::create($this->payload(['status' => 'ACTIVE']));
        $edit = ['name' => 'Gestión actualizada', 'start_date' => '2027-01-02', 'end_date' => '2027-06-29'];
        $this->putJson(self::BASE.'/'.$term->id, $edit)->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.id', $term->id);
        $audit = AuditLog::firstOrFail();
        $this->assertSame('ACADEMIC_TERM_UPDATED', $audit->action);
        $this->assertSame('2027-01-01', $audit->old_values['start_date']);
        $this->assertSame('2027-01-02', $audit->new_values['start_date']);
        $updated = $term->fresh()->getRawOriginal('updated_at');
        $this->travel(5)->seconds();
        $this->putJson(self::BASE.'/'.$term->id, $edit)->assertOk();
        $this->assertSame($updated, $term->fresh()->getRawOriginal('updated_at'));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->travelBack();
    }

    public function test_duplicates_overlaps_and_second_active_have_contract_errors(): void
    {
        $term = AcademicTerm::create($this->payload(['status' => 'ACTIVE']));
        $later = ['start_date' => '2028-01-01', 'end_date' => '2028-06-30'];
        $this->postJson(self::BASE, $this->payload($later + ['name' => ' i/2027 ']))->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_NAME_DUPLICATE')->assertJsonValidationErrors('name');
        $this->postJson(self::BASE, $this->payload(['name' => 'Solapado', 'start_date' => '2027-06-30', 'end_date' => '2027-12-31']))
            ->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_OVERLAP')->assertJsonPath('conflicting_period.id', $term->id)->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->postJson(self::BASE, $this->payload($later + ['name' => 'I/2028', 'status' => 'ACTIVE']))
            ->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_TERM_ACTIVE_CONFLICT')->assertJsonPath('conflicting_period.id', $term->id);
        $this->assertDatabaseCount('academic_terms', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('ACTIVE', $term->fresh()->status);
    }

    public function test_edit_rejects_duplicate_and_overlap_and_preserves_original(): void
    {
        $first = AcademicTerm::create($this->payload());
        $second = AcademicTerm::create($this->payload(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31']));
        $before = $second->fresh()->getAttributes();
        $this->putJson(self::BASE.'/'.$second->id, ['name' => ' i/2027 ', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31'])
            ->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_NAME_DUPLICATE');
        $this->putJson(self::BASE.'/'.$second->id, ['name' => 'II/2027', 'start_date' => '2027-06-30', 'end_date' => '2027-12-31'])
            ->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_OVERLAP')->assertJsonPath('conflicting_period.id', $first->id);
        $this->assertSame($before, $second->fresh()->getAttributes());
    }

    public function test_missing_fields_invalid_dates_and_protected_payload_are_rejected(): void
    {
        $this->postJson(self::BASE, [])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors(['name', 'start_date', 'end_date', 'status']);
        foreach (['2027-02-29', '2027-13-01', '01/01/2027', '0000-01-01', '2027-01-01T12:00:00Z'] as $date) {
            $this->postJson(self::BASE, $this->payload(['start_date' => $date]))->assertUnprocessable()->assertJsonValidationErrors('start_date');
        }
        $this->postJson(self::BASE, $this->payload(['end_date' => '2026-12-31']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->postJson(self::BASE, $this->payload() + ['id' => null, 'created_at' => '2000-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['id', 'created_at']);
        $this->assertDatabaseCount('academic_terms', 0);
        $term = AcademicTerm::create($this->payload());
        $this->putJson(self::BASE.'/'.$term->id, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson(self::BASE.'/99999', ['name' => 'Otro', 'start_date' => '2028-01-01', 'end_date' => '2028-06-30'])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_create_and_edit_without_exposing_details(): void
    {
        $term = AcademicTerm::create($this->payload());
        $before = $term->fresh()->getAttributes();
        $this->mock(AuditLogService::class)->shouldReceive('log')->twice()->andThrow(new \RuntimeException('Sensitive SQL diagnostic'));
        $this->postJson(self::BASE, $this->payload(['name' => 'I/2028', 'start_date' => '2028-01-01', 'end_date' => '2028-06-30']))
            ->assertStatus(500)->assertExactJson(['message' => 'No se pudo completar la operación.', 'code' => 'INTERNAL_ERROR']);
        $this->putJson(self::BASE.'/'.$term->id, ['name' => 'Renombrado', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30'])->assertStatus(500);
        $this->assertSame($before, $term->fresh()->getAttributes());
        $this->assertDatabaseCount('academic_terms', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_write_endpoints_reject_inactive_and_non_admin_users(): void
    {
        $term = AcademicTerm::create($this->payload());
        $this->admin->update(['status' => 'INACTIVE']);
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->putJson(self::BASE.'/'.$term->id, $this->payload())->assertForbidden();
        $this->admin->update(['status' => 'ACTIVE']);
        $this->admin->roles()->detach();
        $this->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE'], $overrides);
    }
}
