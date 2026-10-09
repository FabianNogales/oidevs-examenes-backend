<?php

namespace Tests\Feature\Subjects;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Role;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Subjects\SubjectService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class SubjectStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Subject $subject;
    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $this->career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $this->subject->careers()->attach($this->career->id);
    }

    public function test_status_changes_are_audited_and_repeated_requests_have_no_side_effects(): void
    {
        foreach (['INACTIVE', 'ACTIVE'] as $status) {
            $this->patchJson($this->endpoint(), ['status' => $status])->assertOk()
                ->assertJsonPath('data.id', $this->subject->id)->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.careers.0.id', $this->career->id);
            $updatedAt = $this->subject->refresh()->getRawOriginal('updated_at');
            $count = AuditLog::count();
            $this->travel(5)->seconds();
            $this->patchJson($this->endpoint(), ['status' => $status])->assertOk();
            $this->assertSame($updatedAt, $this->subject->refresh()->getRawOriginal('updated_at'));
            $this->assertSame($count, AuditLog::count());
            $this->travelBack();
        }
        $audit = AuditLog::where('action', 'SUBJECT_DEACTIVATED')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($this->subject->id, $audit->entity_id);
        $this->assertSame('ACTIVE', $audit->old_values['status']);
        $this->assertSame('INACTIVE', $audit->new_values['status']);
        $this->assertSame([$this->career->id], $audit->new_values['career_ids']);
    }

    public function test_invalid_status_and_extra_fields_are_rejected(): void
    {
        foreach ([null, 'active', 'OCCUPIED', 1, ['ACTIVE']] as $status) {
            $this->patchJson($this->endpoint(), ['status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->patchJson($this->endpoint(), [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE', 'code' => 'OTHER', 'career_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'career_ids']);
        $this->assertSame('ACTIVE', $this->subject->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->patchJson('/api/v1/admin/subjects/99999/status', ['status' => 'ACTIVE'])->assertNotFound();
    }

    public function test_permissions_and_password_change_are_required(): void
    {
        $this->admin->forceFill(['must_change_password' => true])->save();
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->admin->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertForbidden();
        $this->admin->update(['status' => 'ACTIVE']);
        $this->admin->roles()->detach();
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertForbidden();
        $this->assertSame('ACTIVE', $this->subject->refresh()->status);
    }

    public function test_audit_failure_rolls_back_status(): void
    {
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $request = Request::create($this->endpoint(), 'PATCH');
        $request->setUserResolver(fn () => $this->admin);
        try {
            app(SubjectService::class)->changeStatus($this->subject, 'INACTIVE', $request);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('ACTIVE', $this->subject->refresh()->status);
        $this->assertDatabaseCount('career_subject', 1);
    }

    public function test_deactivation_preserves_offering_exam_enrollment_and_career_history(): void
    {
        $teacher = Teacher::factory()->create();
        $term = DB::table('academic_terms')->insertGetId([
            'name' => '2026-II', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE',
        ]);
        $offering = DB::table('course_offerings')->insertGetId([
            'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'academic_term_id' => $term, 'status' => 'ACTIVE',
        ]);
        $room = Room::create(['code' => 'A-01', 'status' => 'ACTIVE']);
        $exam = DB::table('exams')->insertGetId([
            'course_offering_id' => $offering, 'room_id' => $room->id, 'name' => 'Parcial', 'exam_date' => '2026-10-09',
            'start_time' => '10:00:00', 'duration_minutes' => 60, 'evaluation_type' => 'partial', 'status' => 'SCHEDULED', 'created_by' => $this->admin->id,
        ]);
        $student = DB::table('students')->insertGetId([
            'user_id' => User::factory()->create()->id, 'sis_code' => '202600001', 'identity_number' => '81000001',
            'first_names' => 'Ana', 'last_names' => 'Rojas', 'career_id' => $this->career->id, 'status' => 'ACTIVE',
        ]);
        $enrollment = DB::table('enrollments')->insertGetId([
            'course_offering_id' => $offering, 'student_id' => $student, 'status' => 'ACTIVE', 'registered_by' => $this->admin->id,
        ]);
        $before = [];
        foreach (['course_offerings' => $offering, 'exams' => $exam, 'enrollments' => $enrollment] as $table => $id) {
            $before[$table] = (array) DB::table($table)->find($id);
        }
        $pivot = (array) DB::table('career_subject')->where('subject_id', $this->subject->id)->first();
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertOk();
        foreach ($before as $table => $row) {
            $this->assertSame($row, (array) DB::table($table)->find($row['id']));
        }
        $this->assertSame($pivot, (array) DB::table('career_subject')->where('subject_id', $this->subject->id)->first());
        $this->getJson('/api/v1/admin/subjects/'.$this->subject->id)->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson('/api/v1/admin/subjects?status=INACTIVE')->assertOk()->assertJsonCount(1, 'data');

        // Isolate the offering FK from the pivot FK when testing physical deletion.
        $this->subject->careers()->detach();
        try {
            DB::transaction(fn () => $this->subject->delete());
            $this->fail('Expected restrictive offering foreign key.');
        } catch (QueryException $exception) {
            $this->assertDatabaseHas('subjects', ['id' => $this->subject->id]);
        }
        $this->assertDatabaseHas('course_offerings', ['id' => $offering]);
    }

    public function test_physical_deletion_is_not_exposed_by_api(): void
    {
        $this->deleteJson('/api/v1/admin/subjects/'.$this->subject->id)->assertStatus(405);
        $this->assertDatabaseHas('subjects', ['id' => $this->subject->id]);
    }

    private function endpoint(): string
    {
        return '/api/v1/admin/subjects/'.$this->subject->id.'/status';
    }
}
