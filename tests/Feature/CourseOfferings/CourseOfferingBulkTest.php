<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\CourseOfferings\CourseOfferingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CourseOfferingBulkTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/course-offerings/bulk';
    private User $admin;
    private Teacher $teacher;
    private Role $teacherRole;
    private AcademicTerm $term;
    private Subject $subject;

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
        $this->teacher = Teacher::factory()->create();
        $this->teacherRole = Role::create(['name' => 'DOCENTE', 'status' => 'ACTIVE']);
        $this->teacher->user->roles()->attach($this->teacherRole->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $this->subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
    }

    public function test_assignment_of_more_than_five_subjects_returns_full_resources_and_audits(): void
    {
        $ids = [$this->subject->id];
        for ($i = 2; $i <= 7; $i++) {
            $ids[] = Subject::create(['code' => 'INF-'.$i, 'name' => 'Materia '.$i, 'status' => 'ACTIVE'])->id;
        }
        $response = $this->postJson(self::ENDPOINT, $this->payload(['subject_ids' => $ids]))->assertCreated()->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.teacher.id', $this->teacher->id)->assertJsonPath('data.0.academic_term.id', $this->term->id)
            ->assertJsonPath('data.0.subject.careers', [])->assertJsonPath('data.0.status', 'ACTIVE');
        $this->assertCount(7, array_unique(array_column($response->json('data'), 'id')));
        $this->assertSame($ids, array_column(array_column($response->json('data'), 'subject'), 'id'));
        $this->assertDatabaseCount('course_offerings', 7);
        $this->assertDatabaseCount('audit_logs', 7);
        $audit = AuditLog::where('action', 'COURSE_OFFERING_CREATED')->firstOrFail();
        $this->assertSame('COURSE_OFFERING', $audit->entity_type);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertNull($audit->old_values);
        $this->assertSame($this->teacher->id, $audit->new_values['teacher_id']);
    }

    public function test_active_and_inactive_duplicates_reject_entire_batch_without_reactivation(): void
    {
        $other = Subject::create(['code' => 'OTHER', 'name' => 'Otra materia', 'status' => 'ACTIVE']);
        $existing = CourseOffering::create(['teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'academic_term_id' => $this->term->id, 'status' => 'ACTIVE']);
        foreach (['ACTIVE', 'INACTIVE'] as $status) {
            $existing->update(['status' => $status]);
            $this->postJson(self::ENDPOINT, $this->payload(['subject_ids' => [$other->id, $this->subject->id]]))
                ->assertStatus(409)->assertJsonPath('code', 'COURSE_OFFERING_DUPLICATE')->assertJsonValidationErrors('subject_ids.1');
            $this->assertSame($status, $existing->fresh()->status);
        }
        $this->assertDatabaseCount('course_offerings', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_payload_and_protected_fields_never_write(): void
    {
        $this->postJson(self::ENDPOINT, [])->assertUnprocessable()->assertJsonValidationErrors(['teacher_id', 'academic_term_id', 'subject_ids']);
        foreach ([[], [$this->subject->id, $this->subject->id], [(string) $this->subject->id], [99999], [0], ['foo' => $this->subject->id]] as $ids) {
            $this->postJson(self::ENDPOINT, $this->payload(['subject_ids' => $ids]))->assertUnprocessable();
        }
        $this->postJson(self::ENDPOINT, $this->payload(['teacher_id' => 99999, 'academic_term_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors(['teacher_id', 'academic_term_id']);
        $this->postJson(self::ENDPOINT, $this->payload() + ['status' => 'INACTIVE', 'id' => null])->assertUnprocessable()->assertJsonValidationErrors(['status', 'id']);
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_inactive_teacher_account_role_or_role_assignment_is_rejected(): void
    {
        foreach (['teacher', 'account', 'role', 'assignment'] as $case) {
            $this->teacher->update(['status' => 'ACTIVE']);
            $this->teacher->user->update(['status' => 'ACTIVE']);
            $this->teacherRole->update(['status' => 'ACTIVE']);
            $this->teacher->user->roles()->updateExistingPivot($this->teacherRole->id, ['status' => 'ACTIVE']);
            if ($case === 'teacher') {
                $this->teacher->update(['status' => 'INACTIVE']);
            } elseif ($case === 'account') {
                $this->teacher->user->update(['status' => 'INACTIVE']);
            } elseif ($case === 'role') {
                $this->teacherRole->update(['status' => 'INACTIVE']);
            } else {
                $this->teacher->user->roles()->updateExistingPivot($this->teacherRole->id, ['status' => 'INACTIVE']);
            }
            $this->postJson(self::ENDPOINT, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        }
        $this->assertDatabaseCount('course_offerings', 0);
    }

    public function test_subject_and_period_states_are_revalidated_and_multiple_active_periods_are_rejected(): void
    {
        $bad = Subject::create(['code' => 'BAD', 'name' => 'Inactiva', 'status' => 'INACTIVE']);
        $this->postJson(self::ENDPOINT, $this->payload(['subject_ids' => [$this->subject->id, $bad->id]]))->assertUnprocessable()->assertJsonValidationErrors('subject_ids.1');
        $this->term->update(['status' => 'INACTIVE']);
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_INACTIVE');
        $this->term->update(['status' => 'ACTIVE']);
        AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_TERM_STATE_INCONSISTENT');
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reference_state_change_after_request_validation_is_rechecked_in_service(): void
    {
        $data = $this->payload();
        $this->subject->update(['status' => 'INACTIVE']);
        $request = Request::create(self::ENDPOINT, 'POST');
        $request->setUserResolver(fn () => $this->admin);
        try {
            app(CourseOfferingService::class)->bulk($data, $request);
            $this->fail('Expected inactive subject rejection.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
        }
        $this->assertDatabaseCount('course_offerings', 0);
    }

    public function test_audit_failure_on_second_subject_rolls_back_entire_manual_operation(): void
    {
        $other = Subject::create(['code' => 'OTHER', 'name' => 'Otra materia', 'status' => 'ACTIVE']);
        $calls = 0;
        $this->mock(AuditLogService::class)->shouldReceive('log')->twice()->andReturnUsing(function (...$arguments) use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Private diagnostic');
            }

            return (new AuditLogService)->log(...$arguments);
        });
        $this->postJson(self::ENDPOINT, $this->payload(['subject_ids' => [$this->subject->id, $other->id]]))->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')
            ->assertJsonMissing(['message' => 'Private diagnostic']);
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_administrator_cannot_assign_subjects(): void
    {
        $this->admin->roles()->detach();
        $this->postJson(self::ENDPOINT, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('course_offerings', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['teacher_id' => $this->teacher->id, 'academic_term_id' => $this->term->id, 'subject_ids' => [$this->subject->id]], $overrides);
    }
}
