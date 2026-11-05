<?php

namespace Tests\Feature\Attendance;

use App\Models\ExamCollaborator;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class AttendanceCollaboratorsTest extends TestCase
{
    use EntryAnnulmentFixtures;
    use RefreshDatabase;

    private function endpoint(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/attendance/collaborators';
    }

    private function entryFor(User $verifier): int
    {
        $student = $this->student->replicate();
        $student->user_id = User::factory()->create()->id;
        $student->sis_code = 'NEW-'.(Student::max('id') + 1);
        $student->identity_number = $student->sis_code;
        $student->save();
        DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id,
            'student_id' => $student->id, 'registered_by' => $this->owner->id, 'status' => 'ACTIVE']);

        return DB::table('exam_entries')->insertGetId(['exam_id' => $this->exam->id,
            'student_id' => $student->id, 'room_id' => $this->exam->room_id, 'verified_by' => $verifier->id,
            'verification_method' => 'MANUAL', 'entered_at' => now(), 'status' => 'VALID']);
    }

    public function test_activity_does_not_invent_assigned_lists_or_offline_counts(): void
    {
        $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk()
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('collaborators.available', true)
            ->assertJsonPath('collaborators.metric', 'VALID_ENTRIES_VERIFIED_BY_USER')
            ->assertJsonPath('collaborators.progress_by_assigned_list_available', false)
            ->assertJsonPath('exclusive_lists.available', false)->assertJsonPath('offline.pending_count', null)
            ->assertJsonPath('collaborators.data.0.role', 'RESPONSIBLE_TEACHER')
            ->assertJsonPath('collaborators.data.0.valid_registered', 0)
            ->assertJsonPath('collaborators.data.0.assigned_total', null)
            ->assertJsonPath('collaborators.data.0.pending', null)->assertJsonPath('collaborators.data.0.attendance_percentage', null);
    }

    public function test_current_and_revoked_verifiers_have_correct_counts_without_changing_official_total(): void
    {
        $active = $this->collaborator();
        $revoked = $this->collaborator();
        $empty = $this->collaborator();
        $this->initialEntry();
        $this->entryFor($active);
        $this->entryFor($revoked);
        ExamCollaborator::where('user_id', $revoked->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $response = $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk()->assertJsonPath('summary.registered', 3);
        $rows = collect($response->json('collaborators.data'))->keyBy('user_id');
        $this->assertSame(1, $rows[$this->owner->id]['valid_registered']);
        $this->assertSame(1, $rows[$active->id]['valid_registered']);
        $this->assertSame(1, $rows[$revoked->id]['valid_registered']);
        $this->assertSame('REVOKED', $rows[$revoked->id]['assignment_status']);
        $this->assertSame(0, $rows[$empty->id]['valid_registered']);
        $this->assertSame(3, $rows->sum('valid_registered'));
        $this->assertArrayNotHasKey('access_code_hash', $rows[$active->id]);
    }

    public function test_annulled_nonvalid_and_unenrolled_entries_are_not_counted_as_activity(): void
    {
        $collaborator = $this->collaborator();
        $id = $this->entryFor($collaborator);
        $this->actingAs($this->owner)->postJson($this->annulPath($id), ['confirmed' => true, 'reason' => 'Registro incorrecto.'])->assertOk();
        $other = $this->entryFor($collaborator);
        DB::table('exam_entries')->where('id', $other)->update(['status' => 'PENDING_SYNC']);
        $third = $this->entryFor($collaborator);
        DB::table('enrollments')->where('student_id', DB::table('exam_entries')->where('id', $third)->value('student_id'))
            ->update(['status' => 'INACTIVE']);
        $response = $this->getJson($this->endpoint())->assertOk()->assertJsonPath('summary.registered', 0);
        $this->assertSame(0, collect($response->json('collaborators.data'))->firstWhere('user_id', $collaborator->id)['valid_registered']);
    }

    public function test_unassigned_historical_verifier_is_not_lost_and_other_exams_are_excluded(): void
    {
        $historical = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->entryFor($historical);
        $other = $this->exam->replicate();
        $other->save();
        $id = $this->initialEntry();
        DB::table('exam_entries')->where('id', $id)->update(['exam_id' => $other->id]);
        $response = $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk()->assertJsonPath('summary.registered', 1);
        $row = collect($response->json('collaborators.data'))->firstWhere('user_id', $historical->id);
        $this->assertSame('HISTORICAL_VERIFIER', $row['role']);
        $this->assertSame(1, $row['valid_registered']);
    }

    public function test_readonly_activity_requires_owner_and_does_not_modify_history(): void
    {
        $this->initialEntry();
        $before = DB::table('exam_entries')->get()->toJson();
        $audits = DB::table('audit_logs')->get()->toJson();
        $this->getJson($this->endpoint())->assertUnauthorized();
        $this->actingAs($this->collaborator())->getJson($this->endpoint())->assertForbidden();
        $response = $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('exam_entries')->get()->toJson());
        $this->assertSame($audits, DB::table('audit_logs')->get()->toJson());
        foreach (['list_id=1', 'collaborator_id=1', 'offline_pending=200'] as $query) {
            $this->getJson($this->endpoint().'?'.$query)->assertUnprocessable();
        }
    }
}
