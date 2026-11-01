<?php

namespace Tests\Feature\Infractions;

use App\Models\Infraction;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InfractionFixtures;
use Tests\TestCase;

class InfractionManagementTest extends TestCase
{
    use InfractionFixtures;
    use RefreshDatabase;

    private function report(array $override = []): array
    {
        return $this->actingAs($this->owner)->postJson($this->infractionBase(), array_replace($this->infractionPayload(), $override))
            ->assertCreated()->json('data');
    }

    private function foreignExam()
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $user->id, 'institutional_code' => 'FOREIGN', 'identity_number' => 'FOREIGN',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->save();
        $exam = $this->exam->replicate();
        $exam->course_offering_id = $offering->id;
        $exam->save();

        return $exam;
    }

    public function test_options_and_student_selector_are_scoped_to_exam(): void
    {
        $this->actingAs($this->owner)->getJson($this->infractionBase().'/options')->assertOk()
            ->assertJsonPath('data.types.0.value', 'COPYING')->assertJsonPath('data.types.1.value', 'CHEATING')
            ->assertJsonPath('data.types.2.value', 'IMPERSONATION')->assertJsonPath('data.statuses.0.value', 'REPORTED');
        $this->getJson($this->infractionBase().'/students?search='.$this->student->sis_code)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $this->student->id);
        DB::table('enrollments')->delete();
        $this->getJson($this->infractionBase().'/students')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_each_type_is_reported_with_server_author_timestamp_and_observations(): void
    {
        $clock = CarbonImmutable::parse('2026-11-01 10:20:30', 'America/La_Paz');
        $this->travelTo($clock);
        foreach (['COPYING', 'CHEATING', 'IMPERSONATION'] as $type) {
            $data = $this->report(['type' => $type, 'description' => '  Descripción del hecho.  ', 'observations' => '  Observación.  ']);
            $this->assertSame('REPORTED', $data['status']);
            $this->assertSame('Descripción del hecho.', $data['description']);
            $this->assertSame('Observación.', $data['observations']);
            $this->assertSame($clock->utc()->toISOString(), $data['occurred_at']);
            $this->assertSame($this->owner->id, $data['reported_by']);
            $this->assertGreaterThan(0, $data['revision']);
            $this->assertDatabaseHas('infractions', ['id' => $data['id'], 'student_id' => $this->student->id, 'exam_id' => $this->exam->id]);
        }
        $this->assertDatabaseCount('expulsions', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertDatabaseCount('special_cases', 0);
    }

    public function test_observations_are_optional_and_can_be_cleared_without_losing_description(): void
    {
        $data = $this->report(['observations' => null]);
        $this->assertNull($data['observations']);
        $data = $this->patchJson($this->infractionBase().'/'.$data['id'], ['expected_revision' => $data['revision'], 'observations' => '0'])
            ->assertOk()->assertJsonPath('data.observations', '0')->json('data');
        $this->patchJson($this->infractionBase().'/'.$data['id'], ['expected_revision' => $data['revision'], 'observations' => '   '])
            ->assertOk()->assertJsonPath('data.observations', null)->assertJsonPath('data.description', $this->infractionPayload()['description']);
    }

    public function test_required_invalid_and_protected_fields_are_rejected(): void
    {
        $this->actingAs($this->owner);
        foreach ([[], ['student_id' => $this->student->id], array_replace($this->infractionPayload(), ['description' => '  ']),
            array_replace($this->infractionPayload(), ['type' => 'INVALID']), array_replace($this->infractionPayload(), ['student_id' => 999999]),
            array_replace($this->infractionPayload(), ['observations' => ['invalid']])] as $payload) {
            $this->postJson($this->infractionBase(), $payload)->assertUnprocessable();
        }
        foreach (['reported_by', 'occurred_at', 'exam_id', 'status', 'sanction'] as $field) {
            $this->postJson($this->infractionBase(), $this->infractionPayload() + [$field => 'forged'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('infractions', 0);
    }

    public function test_existing_but_unrelated_student_cannot_be_reported(): void
    {
        DB::table('enrollments')->delete();
        $this->actingAs($this->owner)->postJson($this->infractionBase(), $this->infractionPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'status' => 'INELIGIBLE']);
        $this->report();
    }

    public function test_authorized_edit_preserves_identity_and_tracks_old_and_new_values(): void
    {
        $data = $this->report();
        $original = $data;
        $data = $this->patchJson($this->infractionBase().'/'.$data['id'], ['expected_revision' => $data['revision'],
            'description' => 'Descripción corregida.', 'type' => 'CHEATING'])->assertOk()->json('data');
        foreach (['student_id', 'exam_id', 'reported_by', 'occurred_at'] as $field) {
            $this->assertSame($original[$field], $data[$field]);
        }
        $this->assertGreaterThan($original['revision'], $data['revision']);
        $this->getJson($this->infractionBase().'/'.$data['id'].'/history')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'INFRACTION_UPDATED')
            ->assertJsonPath('data.0.before.type', 'COPYING')->assertJsonPath('data.0.after.type', 'CHEATING')
            ->assertJsonPath('data.0.actor_id', $this->owner->id);
        $this->getJson($this->infractionBase().'/'.$data['id'])->assertOk()->assertJsonPath('history.meta.total', 2);
    }

    public function test_status_changes_require_authorized_decision_and_audit_reason_without_sanctions(): void
    {
        $data = $this->report();
        $path = $this->infractionBase().'/'.$data['id'].'/status';
        $this->patchJson($path, ['expected_revision' => $data['revision'], 'status' => 'CONFIRMED'])->assertUnprocessable();
        $data = $this->patchJson($path, ['expected_revision' => $data['revision'], 'status' => 'CONFIRMED', 'reason' => 'Evidencia revisada.'])
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED')->json('data');
        $data = $this->patchJson($path, ['expected_revision' => $data['revision'], 'status' => 'RESOLVED', 'reason' => 'Seguimiento finalizado.'])
            ->assertOk()->assertJsonPath('data.can_edit', false)->assertJsonPath('data.allowed_statuses', [])->json('data');
        $this->getJson($this->infractionBase().'/'.$data['id'].'/history')->assertOk()
            ->assertJsonPath('data.0.after.reason', 'Seguimiento finalizado.')->assertJsonPath('meta.total', 3);
        $this->assertDatabaseCount('expulsions', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
    }

    public function test_report_can_be_resolved_without_automatic_confirmation(): void
    {
        $data = $this->report();
        $this->patchJson($this->infractionBase().'/'.$data['id'].'/status', ['expected_revision' => $data['revision'],
            'status' => 'RESOLVED', 'reason' => 'Reporte aclarado sin confirmación.'])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
    }

    public function test_stale_revision_repeated_status_and_reverse_transition_are_rejected(): void
    {
        $data = $this->report();
        $path = $this->infractionBase().'/'.$data['id'];
        $confirmed = $this->patchJson($path.'/status', ['expected_revision' => $data['revision'], 'status' => 'CONFIRMED', 'reason' => 'Revisión.'])
            ->assertOk()->json('data');
        $this->patchJson($path, ['expected_revision' => $data['revision'], 'description' => 'Cambio desactualizado.'])
            ->assertConflict()->assertJsonPath('code', 'INFRACTION_VERSION_CONFLICT');
        foreach (['CONFIRMED', 'REPORTED'] as $status) {
            $this->patchJson($path.'/status', ['expected_revision' => $confirmed['revision'], 'status' => $status, 'reason' => 'Reintento.'])
                ->assertConflict()->assertJsonPath('code', 'INFRACTION_TRANSITION_NOT_ALLOWED');
        }
        $this->assertSame(2, Infraction::findOrFail($data['id'])->changes()->count());
    }

    public function test_resolved_record_cannot_be_edited_or_reopened(): void
    {
        $data = $this->report();
        $path = $this->infractionBase().'/'.$data['id'];
        $data = $this->patchJson($path.'/status', ['expected_revision' => $data['revision'], 'status' => 'RESOLVED', 'reason' => 'Cierre.'])
            ->assertOk()->json('data');
        $this->patchJson($path, ['expected_revision' => $data['revision'], 'observations' => 'No sobrescribir.'])->assertConflict();
        $this->patchJson($path.'/status', ['expected_revision' => $data['revision'], 'status' => 'REPORTED', 'reason' => 'Reabrir.'])->assertConflict();
    }

    public function test_list_filters_and_empty_pagination_work(): void
    {
        $this->report();
        $data = $this->report(['type' => 'IMPERSONATION']);
        $this->getJson($this->infractionBase().'?type=IMPERSONATION&status=REPORTED&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $data['id']);
        $this->getJson($this->infractionBase().'?search='.$this->student->sis_code)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->infractionBase().'?search=%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->infractionBase().'?page=10')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_student_history_only_contains_exams_owned_by_current_teacher(): void
    {
        $data = $this->report();
        $ownOther = $this->exam->replicate();
        $ownOther->save();
        $foreign = $this->foreignExam();
        foreach ([$ownOther, $foreign] as $exam) {
            Infraction::create(['exam_id' => $exam->id, 'student_id' => $this->student->id, 'type' => 'CHEATING',
                'description' => 'Antecedente.', 'reported_by' => $this->owner->id, 'occurred_at' => now()->subDay(), 'status' => 'RESOLVED']);
        }
        $this->getJson($this->infractionBase().'/students/'.$this->student->id.'/history')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $data['id']);
        $this->getJson('/api/v1/exams/'.$foreign->id.'/infractions')->assertForbidden();
    }

    public function test_collaborator_cannot_manage_infractions_even_if_they_have_teacher_role(): void
    {
        $data = $this->report();
        $collaborator = $this->collaborator();
        $role = Role::where('name', 'DOCENTE')->firstOrFail();
        $collaborator->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->actingAs($collaborator)->getJson($this->infractionBase())->assertForbidden();
        $this->postJson($this->infractionBase(), $this->infractionPayload())->assertForbidden();
        $this->patchJson($this->infractionBase().'/'.$data['id'].'/status', ['expected_revision' => $data['revision'],
            'status' => 'CONFIRMED', 'reason' => 'No autorizado.'])->assertForbidden();
        $this->assertDatabaseHas('infractions', ['id' => $data['id'], 'status' => 'REPORTED']);
        $this->postJson($this->url(), ['type' => 'SUSPECTED_IMPERSONATION', 'description' => 'Situación por verificar.'])->assertCreated();
        $this->assertDatabaseCount('infractions', 1);
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_foreign_infraction_ids_guests_and_missing_exams_are_rejected(): void
    {
        $this->getJson($this->infractionBase())->assertUnauthorized();
        $data = $this->report();
        $other = $this->exam->replicate();
        $other->save();
        $this->getJson('/api/v1/exams/'.$other->id.'/infractions/'.$data['id'])->assertNotFound();
        $this->getJson($this->infractionBase().'/999999999999999999999999999')->assertNotFound();
        $this->getJson('/api/v1/exams/999999/infractions')->assertNotFound();
        foreach (['per_page=101', 'page=0', 'type=INVALID', 'search[]=bad'] as $query) {
            $this->getJson($this->infractionBase().'?'.$query)->assertUnprocessable();
        }
    }

    public function test_audit_failure_rolls_back_creation_and_modification(): void
    {
        $this->partialMock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->passthru()->ordered();
            $mock->shouldReceive('log')->times(3)->andThrow(new \RuntimeException('Simulated audit failure'))->ordered();
        });
        $data = $this->report();
        $before = DB::table('infractions')->get()->toJson();
        $this->postJson($this->infractionBase(), $this->infractionPayload())->assertStatus(500);
        $this->patchJson($this->infractionBase().'/'.$data['id'], ['expected_revision' => $data['revision'], 'description' => 'Cambio fallido.'])
            ->assertStatus(500);
        $this->patchJson($this->infractionBase().'/'.$data['id'].'/status', ['expected_revision' => $data['revision'],
            'status' => 'CONFIRMED', 'reason' => 'Decisión que debe revertirse.'])->assertStatus(500);
        $this->assertSame($before, DB::table('infractions')->get()->toJson());
        $this->assertSame(1, Infraction::findOrFail($data['id'])->changes()->count());
    }

    public function test_migration_only_adds_optional_observations_and_preserves_existing_record(): void
    {
        $data = $this->report(['observations' => null]);
        $before = (array) DB::table('infractions')->find($data['id']);
        unset($before['observations']);
        $migration = require base_path('database/migrations/2026_11_01_000001_add_observations_to_infractions_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('infractions', 'observations'));
        $migration->up();
        $after = (array) DB::table('infractions')->find($data['id']);
        $this->assertNull($after['observations']);
        unset($after['observations']);
        $this->assertSame($before, $after);
    }
}
