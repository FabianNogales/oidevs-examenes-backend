<?php

namespace Tests\Feature\Entries;

use App\Models\Role;
use App\Models\User;
use App\Services\Entries\EntryHistoryPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class EntryAnnulmentContractTest extends TestCase
{
    use EntryAnnulmentFixtures;
    use RefreshDatabase;

    private function endpoint(int|string $id): string
    {
        return $this->entryBase().'/entries/'.$id.'/annulment';
    }

    public function test_control_and_history_expose_separate_owner_permissions_even_without_available_entries(): void
    {
        $this->actingAs($this->owner)->getJson($this->entryBase().'/students')->assertOk()
            ->assertJsonPath('permissions.can_annul_entries', true)->assertJsonPath('summary.registered', 0);
        $id = $this->initialEntry();
        $collaborator = $this->collaborator();
        $this->actingAs($collaborator)->getJson($this->entryBase().'/students')->assertOk()
            ->assertJsonPath('permissions.can_annul_entries', false);
        $this->getJson($this->entryBase().'/entries')->assertOk()->assertJsonPath('data.0.permissions.can_annul', false);
        $this->actingAs($this->owner)->getJson($this->entryBase().'/entries')->assertOk()
            ->assertJsonPath('data.0.permissions.can_annul', true)->assertJsonPath('data.0.entry.annulment', null);
        $this->exam->update(['status' => 'FINISHED', 'exam_date' => now()->subDay()->toDateString()]);
        $this->getJson($this->entryBase().'/students')->assertOk()->assertJsonPath('exam.entry_available', false)
            ->assertJsonPath('permissions.can_annul_entries', true);
        $this->postJson($this->endpoint($id), ['reason' => 'Corrección después del examen.'])->assertOk();
    }

    public function test_new_endpoint_accepts_only_reason_and_returns_the_same_complete_history_record(): void
    {
        $verifier = $this->collaborator();
        $this->student->update(['sis_code' => '00123', 'identity_number' => '0099']);
        $this->student->user->update(['profile_photo' => 'photos/hu28-test.jpg']);
        $id = $this->initialEntry();
        DB::table('exam_entries')->where('id', $id)->update(['verified_by' => $verifier->id]);
        $before = $this->actingAs($this->owner)->getJson($this->entryBase().'/entries')->assertOk()->json('data.0');
        $response = $this->postJson($this->endpoint($id), ['reason' => '  Registro incorrecto.  '])->assertOk()
            ->assertJsonPath('data.permissions.can_annul', false)->assertJsonPath('data.entry.status', 'ANNULLED')
            ->assertJsonPath('data.entry.annulment.reason', 'Registro incorrecto.')
            ->assertJsonPath('data.entry.annulment.annulled_by.id', $this->owner->id)
            ->assertJsonPath('data.entry.annulment.annulled_by.name', 'Docente Responsable')
            ->assertJsonPath('data.entry.annulment.annulled_at', now()->toISOString())
            ->assertJsonPath('data.student.sis_code', '00123')->assertJsonPath('data.student.identity_number', '0099');
        $after = $response->json('data');
        $this->assertSame($before['student'], $after['student']);
        $this->assertNotEmpty($after['student']['profile_photo_url']);
        foreach (['id', 'exam_id', 'student_id', 'room_id', 'entered_at', 'verification_method', 'verified_by', 'verified_by_name'] as $field) {
            $this->assertSame($before['entry'][$field], $after['entry'][$field], $field);
        }
        $this->getJson($this->entryBase().'/entries')->assertOk()->assertJsonPath('data.0', $after)->assertJsonPath('meta.total', 1);
        $this->getJson($this->entryBase().'/students')->assertOk()
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('data.0.entry_status', 'PENDING')->assertJsonPath('data.0.entry', null);
        $this->postJson($this->endpoint($id), ['reason' => 'Repetición.'])->assertConflict();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_invalid_reason_and_injected_metadata_are_rejected_without_changes(): void
    {
        $id = $this->initialEntry();
        $before = (array) DB::table('exam_entries')->find($id);
        $this->actingAs($this->owner);
        foreach ([[], ['reason' => '   '], ['reason' => []], ['reason' => 10]] as $payload) {
            $this->postJson($this->endpoint($id), $payload)->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        foreach (['confirmed', 'annulled_by', 'annulled_at', 'verified_by', 'exam_id', 'student_id', 'status'] as $field) {
            $this->postJson($this->endpoint($id), ['reason' => 'Motivo válido.', $field => true])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, (array) DB::table('exam_entries')->find($id));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_only_exam_owner_can_use_new_endpoint_and_entry_is_scoped_to_exam(): void
    {
        $id = $this->initialEntry();
        $payload = ['reason' => 'Corrección.'];
        $this->postJson($this->endpoint($id), $payload)->assertUnauthorized();
        $this->actingAs($this->collaborator())->postJson($this->endpoint($id), $payload)->assertForbidden();
        foreach (['ADMINISTRADOR', 'DOCENTE', 'ESTUDIANTE'] as $name) {
            $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
            $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
            $user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
            $this->actingAs($user)->postJson($this->endpoint($id), $payload)->assertForbidden();
        }
        $other = $this->exam->replicate();
        $other->save();
        $this->actingAs($this->owner)->postJson('/api/v1/exams/'.$other->id.'/entry-control/entries/'.$id.'/annulment', $payload)
            ->assertNotFound();
        foreach (['999999', '9999999999999999999999999', '0', 'text'] as $invalid) {
            $this->postJson($this->endpoint($invalid), $payload)->assertNotFound();
        }
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'VALID']);
    }

    public function test_annulator_identity_survives_profile_changes_and_nullified_foreign_key_using_audit_snapshot(): void
    {
        $id = $this->initialEntry();
        $this->actingAs($this->owner)->postJson($this->endpoint($id), ['reason' => 'Corregir registro.'])->assertOk();
        $this->owner->teacher->update(['first_names' => 'Nombre modificado']);
        // Reproduce the existing nullOnDelete relationship without deleting the exam owner.
        DB::table('exam_entries')->where('id', $id)->update(['annulled_by' => null]);
        $this->getJson($this->entryBase().'/entries')->assertOk()
            ->assertJsonPath('data.0.entry.annulment.annulled_by.id', $this->owner->id)
            ->assertJsonPath('data.0.entry.annulment.annulled_by.name', 'Docente Responsable')
            ->assertJsonPath('data.0.entry.verified_by_name', 'Docente Responsable');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_failure_while_preparing_persisted_evidence_rolls_back_entry_and_audit(): void
    {
        $id = $this->initialEntry();
        $before = (array) DB::table('exam_entries')->find($id);
        $this->mock(EntryHistoryPresenter::class, function ($mock) {
            $mock->shouldReceive('find')->once()->andThrow(new \RuntimeException('Simulated evidence failure'));
        });
        $response = $this->actingAs($this->owner)->postJson($this->endpoint($id), ['reason' => 'Corrección.'])
            ->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('Simulated evidence failure', $response->getContent());
        $this->assertSame($before, (array) DB::table('exam_entries')->find($id));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }
}
