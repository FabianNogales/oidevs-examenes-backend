<?php

namespace Tests\Feature\Entries;

use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class EntryAnnulmentTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    private function entry(array $overrides = []): int
    {
        return DB::table('exam_entries')->insertGetId($overrides + [
            'exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'room_id' => $this->exam->room_id,
            'verified_by' => $this->owner->id, 'verification_method' => 'QR', 'entered_at' => now(),
            'status' => 'VALID', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function endpoint(int|string $id): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/entry-control/entries/'.$id.'/annul';
    }

    public function test_owner_annuls_preserving_original_entry_and_recording_server_metadata(): void
    {
        $verifier = $this->collaborator();
        $id = $this->entry(['verified_by' => $verifier->id]);
        $original = (array) DB::table('exam_entries')->find($id);
        $clock = CarbonImmutable::parse('2026-11-01 12:34:56', 'America/La_Paz');
        $this->travelTo($clock);
        $this->actingAs($this->owner)->postJson($this->endpoint($id), ['confirmed' => true, 'reason' => '  Registro incorrecto.  '])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'ANNULLED')
            ->assertJsonPath('data.verified_by', $verifier->id)->assertJsonPath('data.annulled_by', $this->owner->id)
            ->assertJsonPath('data.annulled_at', $clock->utc()->toISOString())
            ->assertJsonPath('data.annulment_reason', 'Registro incorrecto.')->assertJsonPath('data.can_annul', false);
        $saved = (array) DB::table('exam_entries')->find($id);
        foreach (['id', 'exam_id', 'student_id', 'room_id', 'entered_at', 'verified_by', 'verification_method', 'created_at'] as $field) {
            $this->assertSame($original[$field], $saved[$field], $field);
        }
        $this->assertSame('Registro incorrecto.', $saved['annulment_reason']);
        $this->assertDatabaseCount('exam_entries', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->where('entity_id', $id)->count());
    }

    public function test_explicit_confirmation_and_nonempty_justification_are_required(): void
    {
        $id = $this->entry();
        $this->actingAs($this->owner);
        foreach ([[], ['reason' => 'Motivo'], ['confirmed' => true], ['confirmed' => true, 'reason' => '   '],
            ['confirmed' => false, 'reason' => 'Cancelado'], ['confirmed' => 'true', 'reason' => 'Motivo'],
            ['confirmed' => 1, 'reason' => 'Motivo'], ['confirmed' => true, 'reason' => ['Motivo']]] as $payload) {
            $this->postJson($this->endpoint($id), $payload)->assertUnprocessable();
        }
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'VALID', 'annulled_by' => null, 'annulled_at' => null]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_client_cannot_override_original_data_or_annulment_metadata(): void
    {
        $id = $this->entry();
        $this->actingAs($this->owner);
        foreach (['student_id', 'exam_id', 'verified_by', 'entered_at', 'annulled_by', 'annulled_at', 'status'] as $field) {
            $this->postJson($this->endpoint($id), ['confirmed' => true, 'reason' => 'Motivo', $field => 'forged'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'VALID']);
    }

    public function test_collaborator_and_unrelated_users_cannot_annul_even_with_valid_payload(): void
    {
        $id = $this->entry();
        $payload = ['confirmed' => true, 'reason' => 'Motivo'];
        $this->actingAs($this->collaborator())->postJson($this->endpoint($id), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]))
            ->postJson($this->endpoint($id), $payload)->assertForbidden();
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'VALID']);
    }

    public function test_entry_from_another_exam_and_missing_identifiers_are_not_found(): void
    {
        $other = $this->exam->replicate();
        $other->save();
        $id = $this->entry(['exam_id' => $other->id]);
        $this->actingAs($this->owner);
        foreach ([$id, 999999, '999999999999999999999999999', '0', '-1', 'text'] as $identifier) {
            $this->postJson($this->endpoint($identifier), ['confirmed' => true, 'reason' => 'Motivo'])->assertNotFound();
        }
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'VALID']);
    }

    public function test_repeated_annulment_keeps_first_reason_and_only_one_audit(): void
    {
        $id = $this->entry();
        $this->actingAs($this->owner)->postJson($this->endpoint($id), ['confirmed' => true, 'reason' => 'Primer motivo.'])->assertOk();
        $before = (array) DB::table('exam_entries')->find($id);
        $this->postJson($this->endpoint($id), ['confirmed' => true, 'reason' => 'Segundo motivo.'])
            ->assertConflict()->assertJsonPath('code', 'ENTRY_NOT_VALID');
        $this->assertSame($before, (array) DB::table('exam_entries')->find($id));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_audit_failure_rolls_back_all_annulment_fields(): void
    {
        $id = $this->entry();
        $before = (array) DB::table('exam_entries')->find($id);
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        $this->actingAs($this->owner)->postJson($this->endpoint($id), ['confirmed' => true, 'reason' => 'Motivo'])
            ->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertSame($before, (array) DB::table('exam_entries')->find($id));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ENTRY_ANNULLED')->count());
    }

    public function test_history_shows_original_entry_and_annulment_with_owner_only_action(): void
    {
        $id = $this->entry();
        $history = '/api/v1/exams/'.$this->exam->id.'/entry-control/entries';
        $this->actingAs($this->owner)->getJson($history)->assertOk()->assertJsonPath('data.0.entry.can_annul', true);
        $this->actingAs($this->collaborator())->getJson($history)->assertOk()->assertJsonPath('data.0.entry.can_annul', false);
        $this->actingAs($this->owner)->postJson($this->endpoint($id), ['confirmed' => true, 'reason' => 'Registro incorrecto.'])->assertOk();
        $this->getJson($history)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.entry.id', $id)->assertJsonPath('data.0.entry.status', 'ANNULLED')
            ->assertJsonPath('data.0.entry.annulled_by', $this->owner->id)
            ->assertJsonPath('data.0.entry.annulment_reason', 'Registro incorrecto.')
            ->assertJsonPath('data.0.entry.can_annul', false);
    }

    public function test_guest_and_inactive_owner_cannot_annul(): void
    {
        $id = $this->entry();
        $payload = ['confirmed' => true, 'reason' => 'Motivo'];
        $this->postJson($this->endpoint($id), $payload)->assertUnauthorized();
        $this->owner->update(['status' => 'INACTIVE']);
        $this->actingAs($this->owner)->postJson($this->endpoint($id), $payload)->assertUnauthorized();
        $this->assertDatabaseHas('exam_entries', ['id' => $id, 'status' => 'VALID']);
    }
}
