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
use Tests\Support\InfractionFixtures;
use Tests\TestCase;

class InfractionContractTest extends TestCase
{
    use InfractionFixtures;
    use RefreshDatabase;

    private function report(array $payload = []): array
    {
        return $this->actingAs($this->owner)->postJson($this->infractionBase(), array_replace($this->infractionPayload(), $payload))
            ->assertCreated()->json('data');
    }

    private function historyPath(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/students/'.$this->student->id.'/infractions';
    }

    private function teacher(string $code): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::where('name', 'DOCENTE')->firstOrFail();
        $user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        Teacher::create(['user_id' => $user->id, 'institutional_code' => $code, 'identity_number' => $code,
            'first_names' => 'Docente', 'last_names' => $code, 'status' => 'ACTIVE']);

        return $user;
    }

    public function test_empty_list_preserves_exam_permissions_and_real_pagination(): void
    {
        $this->actingAs($this->owner)->getJson($this->infractionBase().'?per_page=10')->assertOk()
            ->assertJsonPath('exam.id', $this->exam->id)->assertJsonPath('exam.subject_name', 'Materia')
            ->assertJsonPath('permissions.can_create', true)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1);
        $this->getJson($this->infractionBase().'?page=2')->assertUnprocessable();
        $this->getJson($this->historyPath())->assertOk()->assertJsonPath('context.exam_id', $this->exam->id)
            ->assertJsonPath('context.student_id', $this->student->id)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_registration_returns_complete_contract_and_persisted_created_event_without_requiring_entry(): void
    {
        $clock = CarbonImmutable::parse('2026-11-03T14:00:00Z');
        $this->travelTo($clock);
        $this->student->update(['sis_code' => '00123', 'identity_number' => '00099']);
        $data = $this->report(['observations' => null]);
        $this->assertSame($this->owner->id, $data['reported_by']['id']);
        $this->assertSame('Docente Responsable', $data['reported_by']['name']);
        $this->assertSame('00123', $data['student']['sis_code']);
        $this->assertSame('00099', $data['student']['identity_number']);
        $this->assertSame(['can_update' => true, 'allowed_statuses' => ['CONFIRMED', 'RESOLVED']], $data['permissions']);
        $detail = $this->getJson($this->infractionBase().'/'.$data['id'])->assertOk()->assertJsonPath('data', $data)
            ->assertJsonPath('exam.subject_name', 'Materia')->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.action', 'CREATED')->assertJsonPath('history.0.from_status', null)
            ->assertJsonPath('history.0.to_status', 'REPORTED')->assertJsonPath('history.0.reason', null)
            ->assertJsonPath('history.0.performed_by.id', $this->owner->id);
        $this->assertSame($clock->toISOString(), $detail->json('history.0.performed_at'));
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertDatabaseCount('expulsions', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
    }

    public function test_same_second_status_changes_have_distinct_stable_versions_and_an_immutable_complete_chain(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03T14:00:00Z'));
        $created = $this->report();
        $path = $this->infractionBase().'/'.$created['id'];
        $initial = $this->getJson($path)->assertOk()->json();
        $rawTimestamp = DB::table('infractions')->where('id', $created['id'])->value('updated_at');
        $confirmed = $this->patchJson($path.'/status', ['status' => 'CONFIRMED', 'reason' => '  Evidencia revisada.  ',
            'expected_updated_at' => $created['updated_at']])->assertOk()->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('history.1.action', 'STATUS_CHANGED')->assertJsonPath('history.1.from_status', 'REPORTED')
            ->assertJsonPath('history.1.reason', 'Evidencia revisada.')->assertJsonCount(2, 'history')->json();
        $this->assertSame($initial['history'][0], $confirmed['history'][0]);
        $this->assertSame('2026-11-03T14:00:00.000001Z', $confirmed['data']['updated_at']);
        $this->getJson($path)->assertOk()->assertExactJson($confirmed);
        $this->patchJson($path.'/status', ['status' => 'RESOLVED', 'reason' => 'Revisión desactualizada.',
            'expected_updated_at' => $created['updated_at']])->assertConflict()->assertJsonPath('code', 'INFRACTION_VERSION_CONFLICT');
        $resolved = $this->patchJson($path.'/status', ['status' => 'RESOLVED', 'reason' => 'Seguimiento finalizado.',
            'expected_updated_at' => $confirmed['data']['updated_at']])->assertOk()->assertJsonCount(3, 'history')
            ->assertJsonPath('data.permissions.can_update', false)->assertJsonPath('data.permissions.allowed_statuses', [])->json();
        $this->assertSame('2026-11-03T14:00:00.000002Z', $resolved['data']['updated_at']);
        $this->assertSame($confirmed['history'], array_slice($resolved['history'], 0, 2));
        foreach (['student', 'type', 'description', 'observations', 'reported_by', 'occurred_at'] as $field) {
            $this->assertSame($created[$field], $resolved['data'][$field], $field);
        }
        $this->assertSame($rawTimestamp, DB::table('infractions')->where('id', $created['id'])->value('updated_at'));
        $this->patchJson($path.'/status', ['status' => 'RESOLVED', 'reason' => 'Repetir.',
            'expected_updated_at' => $resolved['data']['updated_at']])->assertConflict();
        $this->assertSame(3, Infraction::findOrFail($created['id'])->changes()->count());
    }

    public function test_full_detail_contains_more_than_fifteen_events_and_edit_events_keep_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03T14:00:00Z'));
        $data = $this->report();
        for ($i = 0; $i < 16; $i++) {
            $data = $this->patchJson($this->infractionBase().'/'.$data['id'], ['expected_revision' => $data['revision'],
                'observations' => 'Seguimiento '.$i])->assertOk()->json('data');
        }
        $detail = $this->getJson($this->infractionBase().'/'.$data['id'])->assertOk()->assertJsonCount(17, 'history')->json();
        $this->assertSame('CREATED', $detail['history'][0]['action']);
        $this->assertSame('UPDATED', $detail['history'][16]['action']);
        $ids = [];
        foreach ($detail['history'] as $index => $event) {
            $ids[] = $event['id'];
            $this->assertSame('REPORTED', $event['to_status']);
            if ($index > 0) {
                $this->assertSame('REPORTED', $event['from_status']);
                $this->assertGreaterThan($detail['history'][$index - 1]['id'], $event['id']);
                $this->assertGreaterThanOrEqual($detail['history'][$index - 1]['performed_at'], $event['performed_at']);
            }
        }
        $this->assertCount(17, array_unique($ids));
    }

    public function test_current_owner_decision_keeps_original_reporter_and_records_actual_new_actor(): void
    {
        $data = $this->report();
        $newOwner = $this->teacher('NUEVO');
        $this->exam->courseOffering->update(['teacher_id' => $newOwner->teacher->id]);
        $response = $this->actingAs($newOwner)->patchJson($this->infractionBase().'/'.$data['id'].'/status', [
            'status' => 'CONFIRMED', 'reason' => 'Revisión del responsable vigente.', 'expected_updated_at' => $data['updated_at'],
        ])->assertOk()->assertJsonPath('data.reported_by.id', $this->owner->id)
            ->assertJsonPath('history.0.performed_by.id', $this->owner->id)->assertJsonPath('history.1.performed_by.id', $newOwner->id);
        $this->assertSame($data['reported_by'], $response->json('data.reported_by'));
        $this->actingAs($this->owner)->getJson($this->infractionBase().'/'.$data['id'])->assertForbidden();
    }

    public function test_student_history_is_paginated_read_only_and_excludes_other_teachers_exams(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->report();
        }
        $ownedOther = $this->exam->replicate();
        $ownedOther->name = 'Otro examen propio';
        $ownedOther->save();
        $foreignOwner = $this->teacher('AJENO');
        $foreignOffering = $this->exam->courseOffering->replicate();
        $foreignOffering->teacher_id = $foreignOwner->teacher->id;
        $foreignOffering->save();
        $foreign = $this->exam->replicate();
        $foreign->course_offering_id = $foreignOffering->id;
        $foreign->save();
        foreach ([$ownedOther, $foreign] as $exam) {
            Infraction::create(['exam_id' => $exam->id, 'student_id' => $this->student->id, 'type' => 'CHEATING',
                'description' => 'Antecedente registrado.', 'reported_by' => $this->owner->id, 'occurred_at' => now(), 'status' => 'REPORTED']);
        }
        $page = $this->getJson($this->historyPath().'?per_page=10')->assertOk()->assertJsonPath('meta.total', 13)
            ->assertJsonCount(10, 'data')->json();
        foreach ($page['data'] as $row) {
            $this->assertSame($row['exam']['id'], $row['infraction']['exam_id']);
            $this->assertSame($this->student->id, $row['infraction']['student']['id']);
            $this->assertFalse($row['infraction']['permissions']['can_update']);
            $this->assertSame([], $row['infraction']['permissions']['allowed_statuses']);
            $this->assertNotSame($foreign->id, $row['exam']['id']);
        }
        $this->getJson($this->historyPath().'?per_page=10&page=2')->assertOk()->assertJsonCount(3, 'data');
        DB::table('enrollments')->delete();
        // Prior infractions still establish a legitimate association with the context exam.
        $this->getJson($this->historyPath())->assertOk()->assertJsonPath('meta.total', 13);
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/students/999999/infractions')->assertUnprocessable();
    }

    public function test_bad_revision_payloads_and_identity_injection_never_change_the_record(): void
    {
        $data = $this->report();
        $path = $this->infractionBase().'/'.$data['id'].'/status';
        $base = ['status' => 'CONFIRMED', 'reason' => 'Decisión válida.'];
        foreach (['invalid', '2026-11-03 14:00:00', '2026-02-30T14:00:00Z', ['invalid']] as $date) {
            $this->patchJson($path, $base + ['expected_updated_at' => $date])->assertUnprocessable();
        }
        $this->patchJson($path, $base)->assertUnprocessable();
        $this->patchJson($path, ['status' => 'CONFIRMED', 'reason' => '   ', 'expected_updated_at' => $data['updated_at']])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        foreach (['confirmed', 'reported_by', 'occurred_at', 'student_id', 'description', 'sanction'] as $field) {
            $this->patchJson($path, $base + ['expected_updated_at' => $data['updated_at'], $field => 'forged'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson($path, $base + ['expected_updated_at' => $data['updated_at'], 'expected_revision' => $data['revision'] + 1])
            ->assertConflict();
        $this->assertDatabaseHas('infractions', ['id' => $data['id'], 'status' => 'REPORTED']);
        $this->assertSame(1, Infraction::findOrFail($data['id'])->changes()->count());
    }

    public function test_unauthorized_roles_cannot_read_create_change_state_or_view_student_history(): void
    {
        $data = $this->report();
        $admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        foreach ([$admin, $this->collaborator(), $this->student->user, $this->teacher('OTRO')] as $user) {
            $this->actingAs($user)->getJson($this->infractionBase())->assertForbidden();
            $this->getJson($this->infractionBase().'/'.$data['id'])->assertForbidden();
            $this->getJson($this->historyPath())->assertForbidden();
            $this->postJson($this->infractionBase(), $this->infractionPayload())->assertForbidden();
            $this->patchJson($this->infractionBase().'/'.$data['id'].'/status', ['status' => 'CONFIRMED',
                'reason' => 'Sin permiso.', 'expected_updated_at' => $data['updated_at']])->assertForbidden();
        }
        $this->assertDatabaseHas('infractions', ['id' => $data['id'], 'status' => 'REPORTED']);
    }

    public function test_failed_audit_rolls_back_status_version_and_full_history(): void
    {
        $data = $this->report();
        $path = $this->infractionBase().'/'.$data['id'];
        $before = $this->getJson($path)->assertOk()->json();
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        $this->patchJson($path.'/status', ['status' => 'CONFIRMED', 'reason' => 'Debe revertirse.',
            'expected_updated_at' => $data['updated_at']])->assertStatus(500);
        $this->getJson($path)->assertOk()->assertExactJson($before);
        $this->assertDatabaseCount('expulsions', 0);
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
    }

    public function test_unverifiable_legacy_history_is_not_fabricated(): void
    {
        $legacy = Infraction::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'type' => 'COPYING', 'description' => 'Registro previo sin auditoría verificable.',
            'reported_by' => $this->owner->id, 'occurred_at' => now(), 'status' => 'CONFIRMED']);
        $this->actingAs($this->owner)->getJson($this->infractionBase().'/'.$legacy->id)->assertOk()
            ->assertJsonPath('history', [])->assertJsonPath('data.status', 'CONFIRMED');
        $this->assertSame(0, $legacy->changes()->count());
    }
}
