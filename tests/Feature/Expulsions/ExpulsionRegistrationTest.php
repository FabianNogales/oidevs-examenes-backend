<?php

namespace Tests\Feature\Expulsions;

use App\Models\Infraction;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ExpulsionFixtures;
use Tests\TestCase;

class ExpulsionRegistrationTest extends TestCase
{
    use ExpulsionFixtures;
    use RefreshDatabase;

    private function endpoint(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/expulsions';
    }

    private function payload(): array
    {
        return ['student_id' => $this->student->id, 'reason' => '  Medida adoptada por el docente.  '];
    }

    private function infraction(array $overrides = []): Infraction
    {
        return Infraction::create($overrides + ['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'type' => 'COPYING', 'description' => 'Hecho reportado.', 'reported_by' => $this->owner->id,
            'occurred_at' => now()->subMinute(), 'status' => 'REPORTED']);
    }

    public function test_incident_link_requires_same_exam_and_identified_student(): void
    {
        $incident = \App\Models\SpecialCase::create([
            'exam_id' => $this->exam->id, 'student_id' => null,
            'type' => 'IDENTIFICATION', 'description' => 'Documento ilegible.',
            'reported_by' => $this->owner->id, 'status' => 'PENDING',
        ]);
        $this->actingAs($this->owner);
        $caseId = \App\Models\DisciplineCase::where('source_type', 'INCIDENT')->where('source_id', $incident->id)->value('id');
        $payload = $this->payload() + ['discipline_case_id' => $caseId];
        $this->postJson($this->endpoint(), $payload)->assertUnprocessable()->assertJsonValidationErrors('discipline_case_id');
        $incident->update(['student_id' => $this->student->id]);
        $this->postJson($this->endpoint(), $payload)->assertCreated()->assertJsonPath('data.discipline_case_id', $caseId);
        $this->assertDatabaseHas('special_cases', ['id' => $incident->id, 'status' => 'PENDING']);
    }

    public function test_owner_registers_expulsion_with_server_metadata_and_no_infraction(): void
    {
        $this->actingAs($this->owner)->postJson($this->endpoint(), $this->payload())->assertCreated()
            ->assertJsonPath('data.exam_id', $this->exam->id)->assertJsonPath('data.student_id', $this->student->id)
            ->assertJsonPath('data.infraction_id', null)->assertJsonPath('data.condition', 'EXPELLED')
            ->assertJsonPath('data.expelled_by.id', $this->owner->id)->assertJsonPath('data.expelled_at', now()->toISOString())
            ->assertJsonPath('data.reason', 'Medida adoptada por el docente.');
        $this->assertDatabaseCount('expulsions', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
    }

    public function test_optional_infraction_must_belong_to_same_student_and_exam(): void
    {
        $infraction = $this->infraction();
        $otherExam = $this->exam->replicate();
        $otherExam->save();
        $foreign = $this->infraction(['exam_id' => $otherExam->id]);
        $otherStudent = $this->student->replicate();
        $otherStudent->user_id = User::factory()->create()->id;
        $otherStudent->sis_code = '87654321';
        $otherStudent->identity_number = '87654321';
        $otherStudent->save();
        $wrongStudent = $this->infraction(['student_id' => $otherStudent->id]);
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $wrongStudent->id, 999999] as $id) {
            $this->postJson($this->endpoint(), $this->payload() + ['infraction_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('infraction_id');
        }
        $this->postJson($this->endpoint(), $this->payload() + ['infraction_id' => $infraction->id])->assertCreated()
            ->assertJsonPath('data.infraction_id', $infraction->id)->assertJsonPath('data.infraction.status', 'REPORTED');
        $this->assertDatabaseHas('infractions', ['id' => $infraction->id, 'status' => 'REPORTED']);
    }

    public function test_missing_or_invalid_fields_and_forged_metadata_are_rejected(): void
    {
        $this->actingAs($this->owner);
        foreach ([[], ['student_id' => $this->student->id], ['student_id' => $this->student->id, 'reason' => '   '],
            ['student_id' => 999999, 'reason' => 'Motivo'], ['student_id' => $this->student->id, 'reason' => ['invalid']],
            $this->payload() + ['infraction_id' => -1]] as $payload) {
            $this->postJson($this->endpoint(), $payload)->assertUnprocessable();
        }
        foreach (['exam_id', 'expelled_by', 'expelled_at', 'status'] as $field) {
            $this->postJson($this->endpoint(), $this->payload() + [$field => 'forged'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_unrelated_student_is_rejected_but_existing_historical_association_is_allowed(): void
    {
        DB::table('enrollments')->delete();
        $this->actingAs($this->owner)->postJson($this->endpoint(), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'status' => 'INELIGIBLE']);
        $this->postJson($this->endpoint(), $this->payload())->assertCreated();
    }

    public function test_duplicate_preserves_original_reason_timestamp_and_single_audit(): void
    {
        $this->actingAs($this->owner)->postJson($this->endpoint(), $this->payload())->assertCreated();
        $before = DB::table('expulsions')->get()->toJson();
        $this->postJson($this->endpoint(), array_replace($this->payload(), ['reason' => 'No reemplazar.']))
            ->assertConflict()->assertJsonPath('code', 'STUDENT_ALREADY_EXPELLED');
        $this->assertSame($before, DB::table('expulsions')->get()->toJson());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
    }

    public function test_collaborator_cannot_expel_even_with_teacher_role(): void
    {
        $collaborator = $this->collaborator();
        $collaborator->roles()->attach(Role::where('name', 'DOCENTE')->firstOrFail()->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->actingAs($collaborator)->postJson($this->endpoint(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_guest_inactive_owner_and_foreign_exam_requests_are_rejected(): void
    {
        $this->postJson($this->endpoint(), $this->payload())->assertUnauthorized();
        $other = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $other->id, 'institutional_code' => 'OTHER', 'identity_number' => 'OTHER',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->save();
        $foreign = $this->exam->replicate();
        $foreign->course_offering_id = $offering->id;
        $foreign->save();
        $this->actingAs($this->owner)->postJson('/api/v1/exams/'.$foreign->id.'/expulsions', $this->payload())->assertForbidden();
        $this->actingAs($other)
            ->postJson($this->endpoint(), $this->payload())->assertForbidden();
        $this->owner->update(['status' => 'INACTIVE']);
        $this->actingAs($this->owner)->postJson($this->endpoint(), $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_expulsion_preserves_attendance_infractions_enrollment_eligibility_and_qr(): void
    {
        $infraction = $this->infraction();
        DB::table('exam_entries')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'room_id' => $this->exam->room_id, 'verified_by' => $this->owner->id, 'verification_method' => 'MANUAL',
            'entered_at' => now()->subMinute(), 'status' => 'VALID']);
        DB::table('student_qr_tokens')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'token' => (string) Str::uuid(), 'status' => 'ACTIVE', 'generated_at' => now()]);
        $tables = ['exam_entries', 'infractions', 'enrollments', 'exam_eligibilities', 'student_qr_tokens'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->actingAs($this->owner)->postJson($this->endpoint(), $this->payload() + ['infraction_id' => $infraction->id])->assertCreated();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_audit_failure_rolls_back_expulsion(): void
    {
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        $this->actingAs($this->owner)->postJson($this->endpoint(), $this->payload())->assertStatus(500)
            ->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertDatabaseCount('expulsions', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
    }

    public function test_registration_requires_exam_in_progress_and_valid_exam_identifier(): void
    {
        $this->actingAs($this->owner);
        foreach (['CANCELLED', 'FINISHED'] as $status) {
            $this->exam->update(['status' => $status]);
            $this->postJson($this->endpoint(), $this->payload())->assertConflict()->assertJsonPath('code', 'EXPULSION_EXAM_NOT_IN_PROGRESS');
        }
        $this->exam->update(['status' => 'SCHEDULED']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:59:59', 'America/La_Paz'));
        $this->postJson($this->endpoint(), $this->payload())->assertConflict();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:30:00', 'America/La_Paz'));
        $this->postJson($this->endpoint(), $this->payload())->assertConflict();
        $this->postJson('/api/v1/exams/999999/expulsions', $this->payload())->assertNotFound();
        $this->postJson('/api/v1/exams/99999999999999999999999999/expulsions', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('expulsions', 0);
    }
}
