<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class CourseOfferingAccessTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = $this->offeringFixture();
        Sanctum::actingAs($this->fixture['user']);
    }

    public function test_dashboard_preserves_fields_adds_effective_status_and_filters_own_history(): void
    {
        extract($this->fixture);
        $other = Teacher::factory()->create();
        CourseOffering::create(['teacher_id' => $other->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id, 'status' => 'ACTIVE']);
        $old = AcademicTerm::create(['name' => 'II/2026', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'INACTIVE']);
        CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'academic_term_id' => $old->id, 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/teacher/dashboard/subjects?teacher_id='.$other->id)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.course_offering_id', $offering->id)->assertJsonPath('data.0.can_manage', true)
            ->assertJsonPath('data.0.status', 'ACTIVE')->assertJsonPath('data.0.subject.code', 'INF-01')
            ->assertJsonPath('data.0.subject.status', 'ACTIVE')->assertJsonPath('data.0.academic_term.name', 'I/2027')
            ->assertJsonPath('data.1.can_manage', false)->assertJsonPath('data.1.academic_term.status', 'INACTIVE');
        $this->getJson('/api/v1/teacher/dashboard/subjects?academic_term_id='.$old->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_manage', false);
        $this->getJson('/api/v1/teacher/dashboard/subjects?academic_term_id=bad')->assertUnprocessable();
    }

    public function test_each_inactive_reference_blocks_all_writes_but_keeps_history_readable(): void
    {
        extract($this->fixture);
        DB::table('enrollments')->insert(['course_offering_id' => $offering->id, 'student_id' => $studentId, 'registered_by' => $user->id, 'status' => 'ACTIVE']);
        foreach (['offering', 'teacher', 'subject', 'term'] as $key) {
            $model = $this->fixture[$key];
            $model->update(['status' => 'INACTIVE']);
            $this->getJson('/api/v1/teacher/dashboard/subjects')->assertOk()->assertJsonPath('data.0.can_manage', false);
            $this->getJson($this->base().'/enrollments')->assertOk()->assertJsonCount(1, 'data');
            $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'])->assertUnprocessable()->assertJsonPath('code', 'COURSE_OFFERING_READ_ONLY');
            foreach (['bulk', 'bulk/preview'] as $suffix) {
                $this->postJson($this->base().'/enrollments/'.$suffix, ['file' => UploadedFile::fake()->createWithContent('students.csv', "sisCode\n202600001\n")])->assertUnprocessable();
            }
            $this->postJson($this->base().'/exams', $this->examPayload($room->id))->assertUnprocessable();
            $model->update(['status' => 'ACTIVE']);
        }
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('exams', 0);
        $this->assertDatabaseCount('audit_logs', 4); // Dashboard reads retain the existing access audit.
    }

    public function test_another_teacher_cannot_read_or_write_offering_even_when_inactive(): void
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        Teacher::factory()->create(['user_id' => $user->id]);
        $roleId = DB::table('roles')->where('name', 'DOCENTE')->value('id');
        $user->roles()->attach($roleId, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);
        $this->fixture['offering']->update(['status' => 'INACTIVE']);
        $this->getJson($this->base().'/enrollments')->assertForbidden();
        foreach (['enrollments/manual', 'enrollments/bulk', 'enrollments/bulk/preview', 'exams'] as $path) {
            $this->postJson($this->base().'/'.$path)->assertForbidden();
        }
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('exams', 0);
    }

    public function test_multiple_active_periods_allow_history_and_block_writes(): void
    {
        AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->getJson('/api/v1/teacher/dashboard/subjects')->assertOk()->assertJsonPath('data.0.can_manage', false);
        $this->getJson($this->base().'/enrollments')->assertOk();
        $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'])->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_STATE_INCONSISTENT');
    }

    public function test_inactive_account_revoked_role_and_initial_password_prevent_writes(): void
    {
        $user = $this->fixture['user'];
        $user->forceFill(['must_change_password' => true])->save();
        Sanctum::actingAs($user->fresh());
        $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'])->assertForbidden();
        $user->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        Sanctum::actingAs($user->fresh());
        $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'])->assertUnauthorized();
        $user->update(['status' => 'ACTIVE']);
        $roleId = DB::table('roles')->where('name', 'DOCENTE')->value('id');
        $user->roles()->updateExistingPivot($roleId, ['status' => 'INACTIVE']);
        Sanctum::actingAs($user->fresh());
        $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'])->assertForbidden();
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_active_offering_keeps_enrollment_and_exam_response_contracts(): void
    {
        $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'])->assertCreated()->assertJsonPath('data.sisCode', '202600001');
        $this->postJson($this->base().'/exams', $this->examPayload($this->fixture['room']->id))->assertCreated()->assertJsonPath('data.course_offering_id', $this->fixture['offering']->id);
        $this->assertDatabaseCount('exam_eligibilities', 1);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('exams', 1);
    }

    private function base(): string
    {
        return '/api/v1/course-offerings/'.$this->fixture['offering']->id;
    }
}
