<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CourseOfferingCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/course-offerings';

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
    }

    public function test_guest_teacher_and_student_cannot_access_either_endpoint(): void
    {
        $offering = $this->offering();
        foreach ([self::BASE, self::BASE.'/'.$offering->id] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->authenticate($role);
            foreach ([self::BASE, self::BASE.'/'.$offering->id] as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
    }

    public function test_account_role_and_assignment_must_be_active(): void
    {
        $offering = $this->offering();
        foreach (['account', 'role', 'assignment'] as $case) {
            $user = $this->authenticate();
            $role = $user->roles()->first();
            if ($case === 'account') {
                $user->update(['status' => 'INACTIVE']);
            } elseif ($case === 'role') {
                $role->update(['status' => 'INACTIVE']);
            } else {
                $user->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
            }
            foreach ([self::BASE, self::BASE.'/'.$offering->id] as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
    }

    public function test_first_access_and_replaced_sessions_preserve_authentication_codes(): void
    {
        $user = $this->authenticate();
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson(self::BASE)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'active_session_id' => 'other-session'])->save();
        $this->getJson(self::BASE)->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    public function test_empty_catalog_and_missing_detail_follow_contract(): void
    {
        $this->authenticate();
        $this->getJson(self::BASE.'?page=99')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
        $this->getJson(self::BASE.'/99999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson(self::BASE.'/abc')->assertNotFound();
        $this->getJson(self::BASE.'/0')->assertNotFound();
    }

    public function test_list_order_and_pagination_are_stable(): void
    {
        $this->authenticate();
        $first = $this->offering();
        $last = $first;
        for ($i = 2; $i <= 17; $i++) {
            $last = $this->offering($first->teacher, $first->academicTerm, 'INF-'.$i);
        }
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(15, 'data')
            ->assertJsonPath('data.0.id', $last->id)->assertJsonPath('meta.total', 17);
        $this->getJson(self::BASE.'?page=99')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.from', 16)->assertJsonPath('meta.to', 17);
    }

    public function test_combined_filters_never_leak_another_teacher_term_or_status(): void
    {
        $this->authenticate();
        $wanted = $this->offering();
        $this->offering(null, $wanted->academicTerm, 'INF-02');
        $secondTerm = AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'INACTIVE']);
        $this->offering($wanted->teacher, $secondTerm, 'INF-03');
        $this->offering($wanted->teacher, $wanted->academicTerm, 'INF-04', 'INACTIVE');
        $query = http_build_query(['teacher_id' => $wanted->teacher_id, 'academic_term_id' => $wanted->academic_term_id, 'status' => 'ACTIVE', 'search' => 'program']);
        $this->getJson(self::BASE.'?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id)->assertJsonPath('data.0.teacher.id', $wanted->teacher_id)
            ->assertJsonPath('data.0.academic_term.id', $wanted->academic_term_id);
    }

    public function test_search_matches_subject_teacher_and_email_and_escapes_sql_wildcards(): void
    {
        $this->authenticate();
        $teacher = Teacher::factory()->create(['first_names' => 'Ana', 'last_names' => 'Torres', 'institutional_code' => 'DOC-ANA']);
        $teacher->user->update(['email' => 'ana.torres@umss.edu.bo']);
        $offering = $this->offering($teacher, null, 'INF%_!01');
        $this->offering(null, $offering->academicTerm, 'OTHER');
        foreach (['ANA TORRES', 'doc-ana', 'ana.torres@umss.edu.bo', '%_!', 'INF%_!01'] as $search) {
            $this->getJson(self::BASE.'?search='.urlencode($search))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $offering->id);
        }
    }

    public function test_detail_keeps_inactive_history_and_numeric_counts_without_extra_user_data(): void
    {
        $this->authenticate();
        $offering = $this->offering(null, null, 'OLD', 'INACTIVE');
        $offering->academicTerm->update(['status' => 'INACTIVE']);
        $this->getJson(self::BASE.'/'.$offering->id)->assertOk()->assertJsonPath('data.id', $offering->id)
            ->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.subject.careers', [])
            ->assertJsonPath('data.academic_term.start_date', '2027-01-01')->assertJsonPath('data.academic_term.status', 'INACTIVE')
            ->assertJsonPath('data.enrollments_count', 0)->assertJsonPath('data.exams_count', 0)
            ->assertJsonMissingPath('data.teacher.password')->assertJsonMissingPath('data.teacher.identity_number');
    }

    public function test_invalid_filters_are_rejected_with_field_errors(): void
    {
        $this->authenticate();
        $this->getJson(self::BASE.'?page=0&per_page=5&teacher_id=99999&academic_term_id=-1&status=ACTIVO')
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['page', 'per_page', 'teacher_id', 'academic_term_id', 'status']);
        $this->getJson(self::BASE.'?search[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    private function offering(?Teacher $teacher = null, ?AcademicTerm $term = null, string $code = 'INF-01', string $status = 'ACTIVE'): CourseOffering
    {
        $teacher ??= Teacher::factory()->create();
        $term ??= AcademicTerm::first() ?? AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $subject = Subject::create(['code' => $code, 'name' => 'Programación '.$code, 'status' => 'ACTIVE']);

        return CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id, 'status' => $status]);
    }

    private function authenticate(string $name = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
        $role->update(['status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $user->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($user)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());

        return $user;
    }
}
