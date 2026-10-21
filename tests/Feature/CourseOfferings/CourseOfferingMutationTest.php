<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\CourseOffering;
use App\Models\Role;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CourseOfferingMutationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Teacher $teacher;
    private Subject $subject;
    private AcademicTerm $term;
    private CourseOffering $offering;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $adminRole = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($adminRole->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->teacher = Teacher::factory()->create();
        $teacherRole = Role::create(['name' => 'DOCENTE', 'status' => 'ACTIVE']);
        $this->teacher->user->roles()->attach($teacherRole->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $this->term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $this->offering = CourseOffering::create($this->payload() + ['status' => 'ACTIVE']);
    }

    public function test_edit_keeps_id_and_state_and_audits_actual_changes_only(): void
    {
        $this->offering->update(['status' => 'INACTIVE']);
        $other = $this->otherSubject();
        $payload = $this->payload(['subject_id' => $other->id]);
        $this->putJson($this->endpoint(), $payload)->assertOk()->assertJsonPath('data.id', $this->offering->id)
            ->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.subject.id', $other->id);
        $audit = AuditLog::where('action', 'COURSE_OFFERING_UPDATED')->firstOrFail();
        $this->assertSame($this->subject->id, $audit->old_values['subject_id']);
        $this->assertSame($other->id, $audit->new_values['subject_id']);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->travel(5)->seconds();
        $updated = $this->offering->fresh()->getRawOriginal('updated_at');
        $this->putJson($this->endpoint(), $payload)->assertOk();
        $this->assertSame($updated, $this->offering->fresh()->getRawOriginal('updated_at'));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->travelBack();
    }

    public function test_duplicate_and_disabled_references_are_rejected(): void
    {
        $other = $this->otherSubject();
        $existing = CourseOffering::create($this->payload(['subject_id' => $other->id]) + ['status' => 'INACTIVE']);
        $this->putJson($this->endpoint(), $this->payload(['subject_id' => $other->id]))->assertStatus(409)->assertJsonPath('code', 'COURSE_OFFERING_DUPLICATE');
        $existing->delete();
        $other->update(['status' => 'INACTIVE']);
        $this->putJson($this->endpoint(), $this->payload(['subject_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $other->update(['status' => 'ACTIVE']);
        $this->teacher->user->update(['status' => 'INACTIVE']);
        $this->putJson($this->endpoint(), $this->payload(['subject_id' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->assertSame($this->subject->id, $this->offering->fresh()->subject_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_state_changes_are_idempotent_and_audited(): void
    {
        foreach (['INACTIVE', 'ACTIVE'] as $status) {
            $this->patchJson($this->endpoint().'/status', ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
            $count = AuditLog::count();
            $updated = $this->offering->fresh()->getRawOriginal('updated_at');
            $this->travel(5)->seconds();
            $this->patchJson($this->endpoint().'/status', ['status' => $status])->assertOk();
            $this->assertSame($updated, $this->offering->fresh()->getRawOriginal('updated_at'));
            $this->assertSame($count, AuditLog::count());
            $this->travelBack();
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'COURSE_OFFERING_DEACTIVATED', 'entity_id' => $this->offering->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COURSE_OFFERING_ACTIVATED', 'entity_id' => $this->offering->id]);
    }

    public function test_reactivation_requires_all_enabled_references_but_deactivation_does_not(): void
    {
        $this->subject->update(['status' => 'INACTIVE']);
        $this->patchJson($this->endpoint().'/status', ['status' => 'INACTIVE'])->assertOk();
        $this->patchJson($this->endpoint().'/status', ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->subject->update(['status' => 'ACTIVE']);
        $this->teacher->update(['status' => 'INACTIVE']);
        $this->patchJson($this->endpoint().'/status', ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->teacher->update(['status' => 'ACTIVE']);
        Role::where('name', 'DOCENTE')->update(['status' => 'INACTIVE']);
        $this->patchJson($this->endpoint().'/status', ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        Role::where('name', 'DOCENTE')->update(['status' => 'ACTIVE']);
        $this->term->update(['status' => 'INACTIVE']);
        $this->patchJson($this->endpoint().'/status', ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_INACTIVE');
        $this->assertSame('INACTIVE', $this->offering->fresh()->status);
    }

    public function test_cancelled_exam_blocks_combination_change_and_is_preserved_when_deactivated(): void
    {
        $room = Room::create(['code' => 'A-01', 'status' => 'ACTIVE']);
        $examId = DB::table('exams')->insertGetId(['course_offering_id' => $this->offering->id, 'room_id' => $room->id,
            'name' => 'Parcial cancelado', 'exam_date' => '2027-03-01', 'start_time' => '10:00:00', 'duration_minutes' => 60,
            'evaluation_type' => 'partial', 'status' => 'CANCELLED', 'created_by' => $this->admin->id]);
        $before = (array) DB::table('exams')->find($examId);
        $other = $this->otherSubject();
        $this->putJson($this->endpoint(), $this->payload(['subject_id' => $other->id]))->assertStatus(409)->assertJsonPath('code', 'COURSE_OFFERING_HAS_HISTORY');
        $this->patchJson($this->endpoint().'/status', ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('data.exams_count', 1);
        $this->assertSame($before, (array) DB::table('exams')->find($examId));
        $this->assertSame($this->subject->id, $this->offering->fresh()->subject_id);
        $this->deleteJson($this->endpoint())->assertStatus(405);
        try {
            DB::transaction(fn () => $this->offering->delete());
            $this->fail('Expected restrictive foreign key.');
        } catch (QueryException $exception) {
            $this->assertDatabaseHas('course_offerings', ['id' => $this->offering->id]);
        }
    }

    public function test_inactive_enrollment_blocks_period_change_and_stays_unchanged(): void
    {
        $career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $student = DB::table('students')->insertGetId(['user_id' => User::factory()->create()->id, 'sis_code' => '202700001',
            'identity_number' => '81000001', 'first_names' => 'Ana', 'last_names' => 'Torres', 'career_id' => $career->id, 'status' => 'ACTIVE']);
        $id = DB::table('enrollments')->insertGetId(['course_offering_id' => $this->offering->id, 'student_id' => $student, 'status' => 'INACTIVE', 'registered_by' => $this->admin->id]);
        $before = (array) DB::table('enrollments')->find($id);
        $period = AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'INACTIVE']);
        $this->putJson($this->endpoint(), $this->payload(['academic_term_id' => $period->id]))->assertStatus(409)->assertJsonPath('code', 'COURSE_OFFERING_HAS_HISTORY');
        $this->patchJson($this->endpoint().'/status', ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('data.enrollments_count', 1);
        $this->assertSame($before, (array) DB::table('enrollments')->find($id));
        $this->assertSame($this->term->id, $this->offering->fresh()->academic_term_id);
    }

    public function test_invalid_inputs_missing_ids_and_unauthorized_users_are_rejected(): void
    {
        $this->putJson($this->endpoint(), $this->payload() + ['status' => 'INACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson($this->endpoint(), $this->payload(['subject_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->patchJson($this->endpoint().'/status', ['status' => 'OCCUPIED'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson($this->endpoint().'/status', ['status' => 'INACTIVE', 'teacher_id' => $this->teacher->id])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->putJson('/api/v1/admin/course-offerings/99999', $this->payload())->assertNotFound();
        $this->patchJson('/api/v1/admin/course-offerings/99999/status', ['status' => 'INACTIVE'])->assertNotFound();
        $this->admin->roles()->detach();
        $this->putJson($this->endpoint(), $this->payload())->assertForbidden();
        $this->patchJson($this->endpoint().'/status', ['status' => 'INACTIVE'])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_both_edit_and_state(): void
    {
        $other = $this->otherSubject();
        $before = $this->offering->fresh()->getAttributes();
        $this->mock(AuditLogService::class)->shouldReceive('log')->twice()->andThrow(new \RuntimeException('Private diagnostic'));
        $this->putJson($this->endpoint(), $this->payload(['subject_id' => $other->id]))->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->patchJson($this->endpoint().'/status', ['status' => 'INACTIVE'])->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertSame($before, $this->offering->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'academic_term_id' => $this->term->id], $overrides);
    }

    private function otherSubject(): Subject
    {
        return Subject::create(['code' => 'INF-02', 'name' => 'Programación II', 'status' => 'ACTIVE']);
    }

    private function endpoint(): string
    {
        return '/api/v1/admin/course-offerings/'.$this->offering->id;
    }
}
