<?php

namespace Tests\Feature\ExamManagement;

use App\Http\Resources\Exams\ExamManagementResource;
use App\Models\AcademicTerm;
use App\Models\Exam;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Exams\ExamManagementPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class ExamManagementFoundationTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    private ExamManagementPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create($this->examPayload($this->fixture['room']->id) + ['course_offering_id' => $this->fixture['offering']->id,
            'status' => 'SCHEDULED', 'created_by' => $this->fixture['user']->id]);
        $this->policy = app(ExamManagementPolicy::class);
    }

    public function test_resource_matches_contract_and_uses_persisted_timestamp(): void
    {
        $this->exam->update(['rules' => null]);
        $data = $this->resource($this->exam->fresh());
        $this->assertSame($this->exam->id, $data['id']);
        $this->assertSame($this->fixture['offering']->id, $data['course_offering_id']);
        $this->assertSame('INF-01', $data['subject_code']);
        $this->assertSame('Programación I', $data['subject_name']);
        $this->assertSame('I/2027', $data['academic_term_name']);
        $this->assertSame('10:00:00', $data['start_time']);
        $this->assertSame('2026-10-21', $data['exam_date']);
        $this->assertSame(60, $data['duration_minutes']);
        $this->assertSame(['id' => $this->fixture['room']->id, 'code' => 'A-01', 'name' => 'Aula 01'], $data['room']);
        $this->assertArrayHasKey('rules', $data);
        $this->assertNull($data['rules']);
        $this->assertSame($this->exam->fresh()->updated_at->toISOString(), $data['updated_at']);
        $this->assertTrue($data['can_edit']);
        $this->assertTrue($data['can_cancel']);
        $this->assertNull($data['edit_block_reason']);
        $this->assertNull($data['cancel_block_reason']);
        foreach (['created_by', 'teacher_id', 'email', 'password', 'token'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
    }

    public function test_scheduled_and_legacy_active_are_manageable_only_before_start(): void
    {
        foreach (['SCHEDULED', 'ACTIVE'] as $status) {
            $this->exam->update(['status' => $status]);
            $this->assertTrue($this->permissions()['can_edit']);
        }
        $this->travelTo($this->policy->startsAt($this->exam));
        $permissions = $this->permissions();
        $this->assertFalse($permissions['can_edit']);
        $this->assertFalse($permissions['can_cancel']);
        $this->assertSame('El examen ya comenzó.', $permissions['edit_block_reason']);
    }

    public function test_closed_cancelled_in_progress_and_unknown_states_are_read_only(): void
    {
        foreach (['CANCELLED', 'COMPLETED', 'FINISHED', 'IN_PROGRESS', 'LEGACY_OTHER'] as $status) {
            $this->exam->update(['status' => $status]);
            $this->assertTrue($this->policy->canView($this->fixture['user'], $this->exam));
            $permissions = $this->permissions();
            $this->assertFalse($permissions['can_edit']);
            $this->assertFalse($permissions['can_cancel']);
            $this->assertNotEmpty($permissions['edit_block_reason']);
            $this->assertNotEmpty($permissions['cancel_block_reason']);
        }
        $this->assertSame(1, Exam::count());
    }

    public function test_inactive_references_keep_read_access_and_block_management(): void
    {
        foreach (['offering', 'subject', 'teacher', 'term'] as $key) {
            $this->fixture[$key]->update(['status' => 'INACTIVE']);
            $exam = $this->exam->fresh();
            $this->assertTrue($this->policy->canView($this->fixture['user'], $exam));
            $permissions = $this->policy->permissions($exam, $this->fixture['user']);
            $this->assertFalse($permissions['can_edit']);
            $this->assertFalse($permissions['can_cancel']);
            $this->fixture[$key]->update(['status' => 'ACTIVE']);
        }
    }

    public function test_ownership_uses_teacher_profile_instead_of_user_id_and_collaboration_does_not_grant_management(): void
    {
        $otherUser = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $otherTeacher = Teacher::factory()->create(['user_id' => $otherUser->id]);
        $role = Role::where('name', 'DOCENTE')->firstOrFail();
        $otherUser->roles()->attach($role->id, ['status' => 'ACTIVE']);
        DB::table('exam_collaborators')->insert(['exam_id' => $this->exam->id, 'user_id' => $otherUser->id,
            'assigned_by' => $this->fixture['user']->id, 'access_code_hash' => hash('sha256', 'test'), 'status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->assertNotSame($otherTeacher->id, $otherUser->id);
        $this->assertFalse($this->policy->canView($otherUser, $this->exam));
        $this->assertFalse($this->policy->permissions($this->exam, $otherUser)['can_edit']);
        $this->assertTrue($this->policy->canView($this->fixture['user'], $this->exam));
    }

    public function test_inactive_account_or_role_prevents_access_and_initial_password_blocks_management(): void
    {
        $user = $this->fixture['user'];
        $user->forceFill(['must_change_password' => true])->save();
        $this->assertFalse($this->policy->permissions($this->exam, $user->fresh())['can_edit']);
        $user->update(['status' => 'INACTIVE']);
        $this->assertFalse($this->policy->canView($user->fresh(), $this->exam));
        $user->forceFill(['status' => 'ACTIVE', 'must_change_password' => false])->save();
        $role = Role::where('name', 'DOCENTE')->firstOrFail();
        $user->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
        $this->assertFalse($this->policy->canView($user->fresh(), $this->exam));
        $user->roles()->updateExistingPivot($role->id, ['status' => 'ACTIVE']);
        $role->update(['status' => 'INACTIVE']);
        $this->assertFalse($this->policy->canView($user->fresh(), $this->exam));
    }

    public function test_inconsistent_periods_block_management_without_hiding_exam(): void
    {
        AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->assertTrue($this->policy->canView($this->fixture['user'], $this->exam));
        $this->assertFalse($this->permissions()['can_cancel']);
    }

    public function test_historical_resource_allows_missing_room_and_does_not_write_data(): void
    {
        $auditCount = DB::table('audit_logs')->count();
        $exam = $this->exam->fresh()->load('room');
        $exam->setRelation('room', null);
        $this->assertNull($this->resource($exam)['room']);
        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_schedule_uses_la_paz_and_full_interval_across_midnight_with_large_duration(): void
    {
        $this->exam->update(['exam_date' => '2026-10-16', 'start_time' => '23:30:00', 'duration_minutes' => 90]);
        $this->assertSame('2026-10-17T01:00:00-04:00', $this->policy->endsAt($this->exam)->toIso8601String());
        $this->exam->update(['duration_minutes' => 2147483647]);
        $this->assertSame(2147483647, (int) $this->policy->startsAt($this->exam)->diffInMinutes($this->policy->endsAt($this->exam)));
    }

    private function permissions(): array
    {
        return $this->policy->permissions($this->exam->fresh(), $this->fixture['user']->fresh());
    }

    private function resource(Exam $exam): array
    {
        $request = Request::create('/api/v1/teacher/exams/'.$exam->id);
        $request->setUserResolver(fn () => $this->fixture['user']->fresh());

        return (new ExamManagementResource($exam))->resolve($request);
    }
}
