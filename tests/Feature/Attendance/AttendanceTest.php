<?php

namespace Tests\Feature\Attendance;

use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use EntryAnnulmentFixtures;
    use RefreshDatabase;

    private function base(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/attendance';
    }

    private function extraStudent(?string $enrollmentStatus = 'ACTIVE'): Student
    {
        $student = $this->student->replicate();
        $student->user_id = User::factory()->create()->id;
        $student->sis_code = 'SIS-'.($this->student->newQuery()->max('id') + 1);
        $student->identity_number = 'CI-'.$student->sis_code;
        $student->save();
        if ($enrollmentStatus !== null) {
            DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id,
                'student_id' => $student->id, 'registered_by' => $this->owner->id, 'status' => $enrollmentStatus]);
        }

        return $student;
    }

    public function test_summary_counts_active_roster_and_official_entries_with_percentage(): void
    {
        $this->extraStudent();
        $this->extraStudent();
        $this->initialEntry();
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()
            ->assertJsonPath('summary.total_students', 3)->assertJsonPath('summary.registered', 1)
            ->assertJsonPath('summary.pending', 2)->assertJsonPath('summary.attendance_percentage', 33.33)
            ->assertJsonPath('roster_scope', 'ACTIVE_ENROLLMENTS')->assertJsonPath('source', 'SERVER')
            ->assertJsonPath('is_current', true)->assertJsonPath('last_updated_at', now()->toISOString());
    }

    public function test_registered_and_pending_lists_are_separate_and_counter_is_not_filtered_by_search(): void
    {
        $pending = $this->extraStudent();
        $entry = $this->initialEntry();
        $this->actingAs($this->owner)->getJson($this->base().'/students?view=registered')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.student_id', $this->student->id)
            ->assertJsonPath('data.0.attendance_status', 'SERVER_CONFIRMED')->assertJsonPath('data.0.entry.id', $entry);
        $this->getJson($this->base().'/students?view=pending')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.student_id', $pending->id)->assertJsonPath('data.0.attendance_status', 'PENDING')
            ->assertJsonPath('data.0.entry', null);
        $this->getJson($this->base().'/students?'.http_build_query(['view' => 'pending', 'search' => $pending->sis_code]))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('summary.total_students', 2)->assertJsonPath('summary.registered', 1);
        $this->getJson($this->base().'/students?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($this->base().'/students?page=10')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_new_entry_and_annulment_are_reflected_on_next_request(): void
    {
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 0);
        $entry = $this->postJson($this->manualPath(), ['room_id' => $this->exam->room_id])->assertCreated()->json('data.entry.id');
        $this->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 1)
            ->assertJsonPath('summary.pending', 0)->assertJsonPath('summary.attendance_percentage', 100);
        $this->postJson($this->annulPath($entry), ['confirmed' => true, 'reason' => 'Registro incorrecto.'])->assertOk();
        $this->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 0)->assertJsonPath('summary.pending', 1);
        $this->getJson($this->base().'/students?view=registered')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('exam_entries', ['id' => $entry, 'status' => 'ANNULLED']);
    }

    public function test_offline_or_nonvalid_rows_are_not_official_until_accepted(): void
    {
        $id = $this->initialEntry();
        DB::table('exam_entries')->where('id', $id)->update(['status' => 'PENDING_SYNC']);
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 0)
            ->assertJsonPath('offline.available_on_server', false)->assertJsonPath('offline.pending_count', null)
            ->assertJsonPath('offline.included_in_official_attendance', false);
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('data.0.attendance_status', 'PENDING');
        DB::table('exam_entries')->where('id', $id)->update(['status' => 'VALID']);
        $this->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 1);
    }

    public function test_inactive_enrollments_isolated_eligibility_and_other_exam_entries_do_not_inflate_roster(): void
    {
        $inactive = $this->extraStudent('INACTIVE');
        $isolated = $this->extraStudent(null);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $isolated->id, 'status' => 'ELIGIBLE']);
        $otherExam = $this->exam->replicate();
        $otherExam->save();
        $entry = $this->initialEntry();
        DB::table('exam_entries')->where('id', $entry)->update(['exam_id' => $otherExam->id]);
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.total_students', 1)
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('summary.pending', 1);
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['student_id' => $inactive->id])->assertJsonMissing(['student_id' => $isolated->id]);
    }

    public function test_zero_roster_has_zero_percentage_and_empty_lists(): void
    {
        DB::table('enrollments')->delete();
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()
            ->assertJsonPath('summary.total_students', 0)->assertJsonPath('summary.registered', 0)
            ->assertJsonPath('summary.pending', 0)->assertJsonPath('summary.attendance_percentage', 0);
        $this->getJson($this->base().'/students')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_timestamps_are_generated_by_server_each_time_and_responses_are_not_cached(): void
    {
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()->assertHeader('Cache-Control')
            ->assertJsonPath('last_updated_at', now()->toISOString())->assertJsonPath('poll_after_seconds', 5);
        $this->travel(10)->seconds();
        $response = $this->getJson($this->base().'/summary')->assertOk()->assertJsonPath('last_updated_at', now()->toISOString());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_student_search_is_literal_and_invalid_or_untrusted_parameters_are_rejected(): void
    {
        $this->actingAs($this->owner);
        foreach ([$this->student->sis_code, $this->student->identity_number, 'ESTUDIANTE Identificable'] as $search) {
            $this->getJson($this->base().'/students?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 1);
        }
        foreach (['%', '_', "' OR 1=1 --"] as $search) {
            $this->getJson($this->base().'/students?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 0);
        }
        foreach (['view=unknown', 'per_page=101', 'page=0', 'search[]=bad', 'registered=999', 'offline_pending=5', 'list_id=1'] as $query) {
            $this->getJson($this->base().'/students?'.$query)->assertUnprocessable();
        }
    }

    public function test_guest_collaborator_and_nonowner_cannot_consult_supervision(): void
    {
        $this->getJson($this->base().'/summary')->assertUnauthorized();
        $collaborator = $this->collaborator();
        $collaborator->roles()->attach(Role::where('name', 'DOCENTE')->firstOrFail()->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        foreach ([$collaborator, User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false])] as $actor) {
            $this->actingAs($actor)->getJson($this->base().'/summary')->assertForbidden();
            $this->getJson($this->base().'/students')->assertForbidden();
        }
        $otherUser = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $otherUser->id, 'institutional_code' => 'ATTENDANCE', 'identity_number' => 'ATTENDANCE',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->save();
        $exam = $this->exam->replicate();
        $exam->course_offering_id = $offering->id;
        $exam->save();
        $this->actingAs($this->owner)->getJson('/api/v1/exams/'.$exam->id.'/attendance/summary')->assertForbidden();
        $this->getJson('/api/v1/exams/999999/attendance/summary')->assertNotFound();
    }

    public function test_database_failure_does_not_return_previous_counts_as_current(): void
    {
        $this->initialEntry();
        $this->actingAs($this->owner)->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 1);
        DB::listen(function (QueryExecuted $query) {
            if (str_contains($query->sql, '"enrollments" as "en"')) {
                throw new \RuntimeException('Simulated indicator failure');
            }
        });
        $response = $this->getJson($this->base().'/summary')->assertStatus(503)->assertJsonPath('code', 'ATTENDANCE_UNAVAILABLE')
            ->assertJsonPath('is_current', false)->assertJsonPath('data', null)->assertJsonPath('last_updated_at', null)
            ->assertJsonMissingPath('summary');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_expulsion_does_not_remove_original_valid_attendance(): void
    {
        $this->initialEntry();
        $this->actingAs($this->owner)->postJson('/api/v1/exams/'.$this->exam->id.'/expulsions',
            ['student_id' => $this->student->id, 'reason' => 'Medida documentada.'])->assertCreated();
        $this->getJson($this->base().'/summary')->assertOk()->assertJsonPath('summary.registered', 1);
    }
}
