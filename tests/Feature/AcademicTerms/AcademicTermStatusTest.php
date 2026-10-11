<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Career;
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

class AcademicTermStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AcademicTerm $term;
    private const BASE = '/api/v1/admin/academic-terms';

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
        $this->term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE']);
    }

    public function test_activation_deactivation_and_retry_are_audited_without_duplicate_writes(): void
    {
        foreach (['ACTIVE', 'INACTIVE'] as $status) {
            $this->patchJson($this->endpoint(), ['status' => $status])->assertOk()->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.id', $this->term->id)->assertJsonPath('data.start_date', '2027-01-01');
            $updated = $this->term->fresh()->getRawOriginal('updated_at');
            $count = AuditLog::count();
            $this->travel(5)->seconds();
            $this->patchJson($this->endpoint(), ['status' => $status])->assertOk();
            $this->assertSame($updated, $this->term->fresh()->getRawOriginal('updated_at'));
            $this->assertSame($count, AuditLog::count());
            $this->travelBack();
        }
        $this->getJson(self::BASE.'/active')->assertExactJson(['data' => null]);
        $audit = AuditLog::where('action', 'ACADEMIC_TERM_DEACTIVATED')->firstOrFail();
        $this->assertSame('ACADEMIC_TERM', $audit->entity_type);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('ACTIVE', $audit->old_values['status']);
        $this->assertSame('INACTIVE', $audit->new_values['status']);
    }

    public function test_second_active_is_rejected_and_activation_rechecks_legacy_overlaps(): void
    {
        $other = AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->patchJson($this->endpoint(), ['status' => 'ACTIVE'])->assertStatus(409)
            ->assertJsonPath('code', 'ACADEMIC_TERM_ACTIVE_CONFLICT')->assertJsonPath('conflicting_period.id', $other->id);
        $this->assertSame('ACTIVE', $other->fresh()->status);
        $this->assertSame('INACTIVE', $this->term->fresh()->status);
        $other->update(['status' => 'INACTIVE', 'start_date' => '2027-06-30']);
        $this->patchJson($this->endpoint(), ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_OVERLAP');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_bad_payload_unknown_id_and_permissions_are_rejected(): void
    {
        foreach ([null, 'ACTIVO', 'active', 1, ['ACTIVE']] as $status) {
            $this->patchJson($this->endpoint(), ['status' => $status])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->patchJson($this->endpoint(), ['status' => 'ACTIVE', 'name' => 'Cambio'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::BASE.'/99999/status', ['status' => 'ACTIVE'])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->admin->update(['status' => 'INACTIVE']);
        $this->patchJson($this->endpoint(), ['status' => 'ACTIVE'])->assertForbidden();
        $this->admin->update(['status' => 'ACTIVE']);
        $this->admin->roles()->detach();
        $this->patchJson($this->endpoint(), ['status' => 'ACTIVE'])->assertForbidden();
        $this->assertSame('INACTIVE', $this->term->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_status(): void
    {
        $before = $this->term->fresh()->getAttributes();
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Sensitive diagnostic'));
        $this->patchJson($this->endpoint(), ['status' => 'ACTIVE'])->assertStatus(500)
            ->assertExactJson(['message' => 'No se pudo completar la operación.', 'code' => 'INTERNAL_ERROR']);
        $this->assertSame($before, $this->term->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deactivation_preserves_offerings_exams_enrollments_and_subjects(): void
    {
        $this->term->update(['status' => 'ACTIVE']);
        $subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject->id, 'teacher_id' => Teacher::factory()->create()->id,
            'academic_term_id' => $this->term->id, 'status' => 'ACTIVE']);
        $room = Room::create(['code' => 'A-01', 'status' => 'ACTIVE']);
        $exam = DB::table('exams')->insertGetId(['course_offering_id' => $offering, 'room_id' => $room->id,
            'name' => 'Parcial', 'exam_date' => '2027-03-01', 'start_time' => '10:00:00', 'duration_minutes' => 60,
            'evaluation_type' => 'partial', 'status' => 'SCHEDULED', 'created_by' => $this->admin->id]);
        $career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $student = DB::table('students')->insertGetId(['user_id' => User::factory()->create()->id, 'sis_code' => '202600001',
            'identity_number' => '81000001', 'first_names' => 'Ana', 'last_names' => 'Rojas', 'career_id' => $career->id, 'status' => 'ACTIVE']);
        $enrollment = DB::table('enrollments')->insertGetId(['course_offering_id' => $offering, 'student_id' => $student, 'status' => 'ACTIVE', 'registered_by' => $this->admin->id]);
        $before = [];
        foreach (['subjects' => $subject->id, 'course_offerings' => $offering, 'exams' => $exam, 'enrollments' => $enrollment] as $table => $id) {
            $before[$table] = (array) DB::table($table)->find($id);
        }
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertOk();
        foreach ($before as $table => $row) {
            $this->assertSame($row, (array) DB::table($table)->find($row['id']));
        }
        $this->assertSame(1, $this->term->fresh()->courseOfferings()->count());
        $this->getJson(self::BASE.'/'.$this->term->id)->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->deleteJson(self::BASE.'/'.$this->term->id)->assertStatus(405);
        try {
            DB::transaction(fn () => DB::table('academic_terms')->where('id', $this->term->id)->delete());
            $this->fail('Expected restrictive foreign key.');
        } catch (QueryException $exception) {
            $this->assertDatabaseHas('academic_terms', ['id' => $this->term->id]);
        }
    }

    private function endpoint(): string
    {
        return self::BASE.'/'.$this->term->id.'/status';
    }
}
