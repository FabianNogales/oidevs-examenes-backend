<?php

namespace Tests\Feature\Expulsions;

use App\Models\Expulsion;
use App\Models\Infraction;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExpulsionFixtures;
use Tests\TestCase;

class ExpulsionReadTest extends TestCase
{
    use ExpulsionFixtures;
    use RefreshDatabase;

    private function expulsion(array $attributes = []): Expulsion
    {
        return Expulsion::create($attributes + ['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'reason' => 'Medida documentada.', 'expelled_by' => $this->owner->id, 'expelled_at' => now()]);
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

    public function test_empty_list_and_student_selector_identify_condition_and_availability(): void
    {
        $this->actingAs($this->owner)->getJson($this->expulsionBase())->assertOk()->assertJsonPath('meta.total', 0)
            ->assertJsonPath('message', 'No se encontraron expulsiones con los criterios seleccionados.');
        $this->getJson($this->expulsionBase().'/students')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.condition', 'NOT_EXPELLED')->assertJsonPath('data.0.can_expel', true);
        $expulsion = $this->expulsion();
        $this->getJson($this->expulsionBase().'/students')->assertOk()->assertJsonPath('data.0.condition', 'EXPELLED')
            ->assertJsonPath('data.0.expulsion_id', $expulsion->id)->assertJsonPath('data.0.can_expel', false)
            ->assertJsonPath('data.0.expulsion_block_reason', 'STUDENT_ALREADY_EXPELLED');
    }

    public function test_list_detail_search_and_linked_infraction_are_preserved(): void
    {
        $infraction = Infraction::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'type' => 'CHEATING', 'description' => 'Hecho documentado.', 'reported_by' => $this->owner->id, 'occurred_at' => now(), 'status' => 'CONFIRMED']);
        $expulsion = $this->expulsion(['infraction_id' => $infraction->id]);
        $this->actingAs($this->owner)->getJson($this->expulsionBase().'/'.$expulsion->id)->assertOk()
            ->assertJsonPath('data.reason', 'Medida documentada.')->assertJsonPath('data.infraction.id', $infraction->id)
            ->assertJsonPath('data.responsible.id', $this->owner->id)->assertJsonPath('data.condition', 'EXPELLED');
        foreach ([$this->student->sis_code, $this->student->identity_number, 'ESTUDIANTE Identificable'] as $search) {
            $this->getJson($this->expulsionBase().'?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 1);
        }
        foreach (['%', '_', "' OR 1=1 --"] as $search) {
            $this->getJson($this->expulsionBase().'?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->getJson($this->expulsionBase().'?page=10')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_detail_cannot_be_reached_by_using_another_exam_or_malformed_identifier(): void
    {
        $other = $this->exam->replicate();
        $other->save();
        $foreignRecord = $this->expulsion(['exam_id' => $other->id]);
        $this->actingAs($this->owner)->getJson($this->expulsionBase().'/'.$foreignRecord->id)->assertNotFound();
        foreach (['999999', '0', '-1', 'text', '99999999999999999999999999999'] as $id) {
            $this->getJson($this->expulsionBase().'/'.$id)->assertNotFound();
        }
    }

    public function test_student_history_includes_only_authorized_exams_and_survives_removed_enrollment(): void
    {
        $own = $this->expulsion();
        $otherOwn = $this->exam->replicate();
        $otherOwn->save();
        $foreign = $this->foreignExam();
        $this->expulsion(['exam_id' => $otherOwn->id]);
        $this->expulsion(['exam_id' => $foreign->id]);
        DB::table('enrollments')->delete();
        $this->exam->update(['status' => 'FINISHED']);
        $this->actingAs($this->owner)->getJson($this->expulsionBase().'/students/'.$this->student->id.'/history')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->expulsionBase().'/'.$own->id)->assertOk();
        $this->getJson($this->expulsionBase().'/students')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/exams/'.$foreign->id.'/expulsions')->assertForbidden();
    }

    public function test_entry_control_identifies_expulsion_without_changing_attendance_or_blockers(): void
    {
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'status' => 'ELIGIBLE']);
        $base = '/api/v1/exams/'.$this->exam->id.'/entry-control';
        $before = $this->actingAs($this->owner)->getJson($base.'/students/'.$this->student->id)->assertOk()->json('data');
        $this->postJson($this->expulsionBase(), $this->expulsionPayload())->assertCreated();
        $after = $this->getJson($base.'/students/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.is_expelled', true)->assertJsonStructure(['data' => ['expulsion' => ['id', 'expelled_at']]])->json('data');
        $this->assertSame($before['blockers'], $after['blockers']);
        $this->assertSame($before['entry_status'], $after['entry_status']);
        $this->getJson($base.'/students')->assertOk()->assertJsonPath('data.0.is_expelled', true)
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('summary.pending', 1);
        $collaborator = $this->collaborator();
        $this->actingAs($collaborator)->getJson($base.'/students/'.$this->student->id)->assertOk()->assertJsonPath('data.is_expelled', true)
            ->assertJsonMissingPath('data.expulsion.reason');
        $this->getJson($this->expulsionBase())->assertForbidden();
    }

    public function test_other_exam_expulsion_does_not_mark_current_student_as_expelled(): void
    {
        $other = $this->exam->replicate();
        $other->save();
        $this->expulsion(['exam_id' => $other->id]);
        $this->actingAs($this->owner)->getJson($this->expulsionBase().'/students')->assertOk()->assertJsonPath('data.0.condition', 'NOT_EXPELLED');
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/entry-control/students')->assertOk()->assertJsonPath('data.0.is_expelled', false);
    }

    public function test_guest_collaborator_and_unrelated_users_cannot_consult_disciplinary_details(): void
    {
        $record = $this->expulsion();
        $this->getJson($this->expulsionBase())->assertUnauthorized();
        foreach ([$this->collaborator(), User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false])] as $actor) {
            $this->actingAs($actor);
            foreach (['', '/'.$record->id, '/students', '/students/'.$this->student->id.'/history'] as $path) {
                $this->getJson($this->expulsionBase().$path)->assertForbidden();
            }
        }
    }

    public function test_reading_preserves_history_and_rejects_invalid_filters_and_unrelated_students(): void
    {
        $record = $this->expulsion();
        $before = DB::table('expulsions')->get()->toJson();
        $audit = DB::table('audit_logs')->get()->toJson();
        $this->actingAs($this->owner)->getJson($this->expulsionBase().'/'.$record->id)->assertOk();
        $this->getJson($this->expulsionBase())->assertOk();
        $this->getJson($this->expulsionBase().'/students/'.$this->student->id.'/history')->assertOk();
        $this->getJson($this->expulsionBase().'/students/999999/history')->assertNotFound();
        foreach (['page=0', 'per_page=101', 'student_id=-1', 'search[]=invalid'] as $query) {
            $this->getJson($this->expulsionBase().'?'.$query)->assertUnprocessable();
        }
        $this->assertSame($before, DB::table('expulsions')->get()->toJson());
        $this->assertSame($audit, DB::table('audit_logs')->get()->toJson());
    }
}
