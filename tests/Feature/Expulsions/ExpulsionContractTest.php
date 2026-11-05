<?php

namespace Tests\Feature\Expulsions;

use App\Models\Expulsion;
use App\Models\Infraction;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExpulsionFixtures;
use Tests\TestCase;

class ExpulsionContractTest extends TestCase
{
    use ExpulsionFixtures;
    use RefreshDatabase;

    private function eligiblePath(): string
    {
        return $this->expulsionBase().'/eligible-infractions';
    }

    private function historyPath(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/students/'.$this->student->id.'/expulsions';
    }

    private function infraction(array $attributes = []): Infraction
    {
        return Infraction::create($attributes + ['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'type' => 'COPYING', 'description' => 'Uso de apuntes.', 'reported_by' => $this->owner->id,
            'occurred_at' => now(), 'status' => 'REPORTED']);
    }

    private function foreignExam()
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $user->id, 'institutional_code' => 'HU31-OTHER', 'identity_number' => 'HU31-OTHER',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->save();
        $exam = $this->exam->replicate();
        $exam->course_offering_id = $offering->id;
        $exam->save();

        return $exam;
    }

    public function test_empty_list_and_history_include_authorized_context_and_available_registration_permission(): void
    {
        $this->actingAs($this->owner)->getJson($this->expulsionBase().'?per_page=10')->assertOk()
            ->assertJsonPath('exam.id', $this->exam->id)->assertJsonPath('exam.subject_name', 'Materia')
            ->assertJsonPath('permissions.can_create', true)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1);
        $this->getJson($this->historyPath())->assertOk()->assertJsonPath('context.exam_id', $this->exam->id)
            ->assertJsonPath('context.student_id', $this->student->id)->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $this->exam->update(['status' => 'FINISHED']);
        $this->getJson($this->expulsionBase())->assertOk()->assertJsonPath('permissions.can_create', false);
        $this->postJson($this->expulsionBase(), $this->expulsionPayload())->assertConflict()
            ->assertJsonPath('code', 'EXPULSION_EXAM_NOT_IN_PROGRESS');
    }

    public function test_registration_returns_complete_dto_and_detail_preserves_persisted_evidence(): void
    {
        $this->student->update(['sis_code' => '00123', 'identity_number' => '00099']);
        $this->travelTo(now()->addMicroseconds(123456));
        $payload = ['student_id' => $this->student->id, 'reason' => '  Decisión disciplinaria justificada.  ', 'infraction_id' => null];
        $created = $this->actingAs($this->owner)->postJson($this->expulsionBase(), $payload)->assertCreated()
            ->assertJsonPath('data.status', 'EXPELLED')->assertJsonPath('data.infraction', null)
            ->assertJsonPath('data.reason', 'Decisión disciplinaria justificada.')
            ->assertJsonPath('data.expelled_by.id', $this->owner->id)->assertJsonPath('data.expelled_by.name', 'Docente Responsable')
            ->assertJsonPath('data.student.sis_code', '00123')->assertJsonPath('data.student.identity_number', '00099')->json('data');
        $this->assertIsInt($created['id']);
        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:\d{2})$/', $created['expelled_at']);
        $this->getJson($this->expulsionBase().'/'.$created['id'])->assertOk()->assertJsonPath('data', $created)
            ->assertJsonPath('exam.id', $this->exam->id)->assertJsonPath('exam.subject_name', 'Materia');
        $this->getJson($this->expulsionBase())->assertOk()->assertJsonPath('data.0', $created)->assertJsonPath('meta.total', 1);
        $this->postJson($this->expulsionBase(), $payload)->assertConflict()->assertJsonPath('code', 'STUDENT_ALREADY_EXPELLED');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
        $this->assertDatabaseHas('students', ['id' => $this->student->id, 'status' => 'ACTIVE']);
    }

    public function test_eligible_infractions_are_paginated_for_exact_student_and_exam_without_requiring_confirmation(): void
    {
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $ids[] = $this->infraction(['status' => ['REPORTED', 'CONFIRMED', 'RESOLVED'][$i % 3]])->id;
        }
        $other = $this->exam->replicate();
        $other->save();
        $this->infraction(['exam_id' => $other->id]);
        $student = $this->student->replicate();
        $student->user_id = User::factory()->create()->id;
        $student->sis_code = 'OTHER31';
        $student->identity_number = 'OTHER31';
        $student->save();
        $this->infraction(['student_id' => $student->id]);
        $path = $this->eligiblePath().'?student_id='.$this->student->id.'&per_page=10';
        $first = $this->actingAs($this->owner)->getJson($path)->assertOk()->assertJsonPath('meta.total', 13)
            ->assertJsonPath('context.exam_id', $this->exam->id)->assertJsonPath('context.student_id', $this->student->id)
            ->assertJsonCount(10, 'data')->assertJsonPath('data.0.id', $ids[12])->json();
        $second = $this->getJson($path.'&page=2')->assertOk()->assertJsonCount(3, 'data')->json();
        $this->assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
        foreach (array_merge($first['data'], $second['data']) as $record) {
            $this->assertSame($this->exam->id, $record['exam_id']);
            $this->assertSame($this->student->id, $record['student_id']);
        }
        $reported = $ids[0];
        $this->postJson($this->expulsionBase(), $this->expulsionPayload() + ['infraction_id' => $reported])->assertCreated()
            ->assertJsonPath('data.infraction.exam_id', $this->exam->id)->assertJsonPath('data.infraction.student_id', $this->student->id)
            ->assertJsonPath('data.infraction.status', 'REPORTED')->assertJsonPath('data.infraction.type', 'COPYING');
        $this->assertDatabaseHas('infractions', ['id' => $reported, 'status' => 'REPORTED']);
    }

    public function test_selected_infraction_is_revalidated_after_selector_was_loaded(): void
    {
        $infraction = $this->infraction();
        $this->actingAs($this->owner)->getJson($this->eligiblePath().'?student_id='.$this->student->id)->assertOk()
            ->assertJsonPath('data.0.id', $infraction->id);
        $other = $this->exam->replicate();
        $other->save();
        $infraction->update(['exam_id' => $other->id]);
        $this->postJson($this->expulsionBase(), $this->expulsionPayload() + ['infraction_id' => $infraction->id])
            ->assertUnprocessable()->assertJsonValidationErrors('infraction_id');
        $this->assertDatabaseCount('expulsions', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'STUDENT_EXPELLED')->count());
    }

    public function test_student_history_paginates_only_authorized_exams_and_preserves_attendance(): void
    {
        $foreign = $this->foreignExam();
        $exams = [$this->exam, $foreign];
        for ($i = 0; $i < 12; $i++) {
            $exam = $this->exam->replicate();
            $exam->name = 'Examen histórico '.$i;
            $exam->save();
            $exams[] = $exam;
        }
        foreach ($exams as $exam) {
            Expulsion::create(['exam_id' => $exam->id, 'student_id' => $this->student->id,
                'reason' => 'Medida registrada.', 'expelled_by' => $this->owner->id, 'expelled_at' => now()]);
        }
        $attendance = DB::table('exam_entries')->insertGetId(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'room_id' => $this->exam->room_id, 'verified_by' => $this->owner->id, 'verification_method' => 'MANUAL',
            'entered_at' => now(), 'status' => 'VALID']);
        $this->exam->update(['status' => 'FINISHED']);
        DB::table('enrollments')->delete();
        $rows = $this->actingAs($this->owner)->getJson($this->historyPath().'?per_page=10')->assertOk()
            ->assertJsonPath('meta.total', 13)->assertJsonCount(10, 'data')->json('data');
        foreach ($rows as $row) {
            $this->assertSame($row['exam']['id'], $row['expulsion']['exam_id']);
            $this->assertNotSame($foreign->id, $row['exam']['id']);
            $this->assertSame($this->student->id, $row['expulsion']['student']['id']);
            $this->assertSame('Materia', $row['exam']['subject_name']);
        }
        $this->getJson($this->historyPath().'?per_page=10&page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertDatabaseHas('exam_entries', ['id' => $attendance, 'status' => 'VALID']);
        $this->assertDatabaseCount('expulsions', 14);
    }

    public function test_new_endpoints_reject_unauthorized_roles_foreign_exams_and_manipulated_student_ids(): void
    {
        $foreign = $this->foreignExam();
        $this->getJson($this->eligiblePath().'?student_id='.$this->student->id)->assertUnauthorized();
        $this->getJson($this->historyPath())->assertUnauthorized();
        $admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        foreach ([$admin, $this->collaborator(), $this->student->user] as $actor) {
            $this->actingAs($actor)->getJson($this->eligiblePath().'?student_id='.$this->student->id)->assertForbidden();
            $this->getJson($this->historyPath())->assertForbidden();
            $this->postJson($this->expulsionBase(), $this->expulsionPayload())->assertForbidden();
        }
        $this->actingAs($this->owner)->getJson('/api/v1/exams/'.$foreign->id.'/expulsions/eligible-infractions?student_id='.$this->student->id)
            ->assertForbidden();
        $this->getJson('/api/v1/exams/'.$foreign->id.'/students/'.$this->student->id.'/expulsions')->assertForbidden();
        $this->getJson($this->eligiblePath().'?student_id=999999')->assertNotFound();
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/students/999999/expulsions')->assertNotFound();
    }

    public function test_eligible_selector_and_pagination_validate_parameters_and_return_true_empty_results(): void
    {
        $this->actingAs($this->owner)->getJson($this->eligiblePath().'?student_id='.$this->student->id)->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
        foreach (['', 'student_id=0', 'student_id=text', 'student_id[]=1', 'student_id=9999999999999999999999999',
            'student_id='.$this->student->id.'&page=0', 'student_id='.$this->student->id.'&per_page=101'] as $query) {
            $this->getJson($this->eligiblePath().'?'.$query)->assertUnprocessable();
        }
        $this->getJson($this->eligiblePath().'?student_id='.$this->student->id.'&page=2')->assertUnprocessable();
        $this->getJson($this->historyPath().'?page=2')->assertUnprocessable();
    }
}
