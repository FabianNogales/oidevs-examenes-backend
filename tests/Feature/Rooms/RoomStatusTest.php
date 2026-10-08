<?php

namespace Tests\Feature\Rooms;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Rooms\RoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class RoomStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Room $room;

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
        $this->room = Room::create(['code' => 'A-01', 'name' => 'Aula principal', 'capacity' => 50, 'status' => 'ACTIVE']);
    }

    public function test_state_changes_are_audited_and_retries_have_no_effects(): void
    {
        foreach (['INACTIVE', 'ACTIVE'] as $status) {
            $this->patchJson($this->endpoint(), ['status' => $status])->assertOk()
                ->assertJsonPath('data.id', $this->room->id)->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.capacity', 50);
            $updatedAt = $this->room->refresh()->getRawOriginal('updated_at');
            $auditCount = AuditLog::count();
            $this->travel(5)->seconds();
            $this->patchJson($this->endpoint(), ['status' => $status])->assertOk();
            $this->assertSame($updatedAt, $this->room->refresh()->getRawOriginal('updated_at'));
            $this->assertSame($auditCount, AuditLog::count());
            $this->travelBack();
        }
        $audit = AuditLog::where('action', 'ROOM_DEACTIVATED')->firstOrFail();
        $this->assertSame(['status' => 'ACTIVE'], $audit->old_values);
        $this->assertSame(['status' => 'INACTIVE'], $audit->new_values);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_payload_cannot_edit_other_fields_and_invalid_states_are_rejected(): void
    {
        foreach ([null, 'AVAILABLE', 'OCCUPIED', 'active', 1] as $status) {
            $this->patchJson($this->endpoint(), ['status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->patchJson($this->endpoint(), [])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE', 'code' => 'HACK', 'capacity' => 1])->assertOk();
        $this->assertSame('A-01', $this->room->refresh()->code);
        $this->assertSame(50, $this->room->capacity);
        $this->patchJson('/api/v1/admin/rooms/99999/status', ['status' => 'ACTIVE'])->assertNotFound();
    }

    public function test_inactive_administrator_and_non_admin_cannot_change_state(): void
    {
        $this->admin->update(['status' => 'INACTIVE']);
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertForbidden();
        $this->admin->update(['status' => 'ACTIVE']);
        $this->admin->roles()->detach();
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertForbidden();
        $this->assertSame('ACTIVE', $this->room->refresh()->status);
    }

    public function test_audit_failure_rolls_back_state(): void
    {
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $request = Request::create($this->endpoint(), 'PATCH');
        $request->setUserResolver(fn () => $this->admin);
        try {
            app(RoomService::class)->changeStatus($this->room, 'INACTIVE', $request);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('ACTIVE', $this->room->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deactivation_preserves_exam_and_entry_history(): void
    {
        $teacher = Teacher::factory()->create();
        $subject = DB::table('subjects')->insertGetId(['code' => 'CS101', 'name' => 'Programación', 'status' => 'ACTIVE']);
        $term = DB::table('academic_terms')->insertGetId(['name' => '2026-II', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE']);
        $offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject, 'academic_term_id' => $term, 'teacher_id' => $teacher->id, 'status' => 'ACTIVE']);
        $exam = DB::table('exams')->insertGetId(['room_id' => $this->room->id, 'course_offering_id' => $offering,
            'name' => 'Parcial', 'exam_date' => '2026-10-08', 'start_time' => '10:00:00', 'duration_minutes' => 90,
            'evaluation_type' => 'partial', 'status' => 'SCHEDULED', 'created_by' => $this->admin->id]);
        $career = DB::table('careers')->insertGetId(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $student = DB::table('students')->insertGetId(['user_id' => User::factory()->create()->id, 'sis_code' => '202600001',
            'identity_number' => '100001', 'first_names' => 'Ana', 'last_names' => 'Rojas', 'career_id' => $career, 'status' => 'ACTIVE']);
        $entry = DB::table('exam_entries')->insertGetId(['exam_id' => $exam, 'student_id' => $student, 'room_id' => $this->room->id,
            'verified_by' => $this->admin->id, 'verification_method' => 'QR', 'status' => 'VALID']);
        $beforeExam = (array) DB::table('exams')->find($exam);
        $beforeEntry = (array) DB::table('exam_entries')->find($entry);
        $this->patchJson($this->endpoint(), ['status' => 'INACTIVE'])->assertOk();
        $this->assertSame($beforeExam, (array) DB::table('exams')->find($exam));
        $this->assertSame($beforeEntry, (array) DB::table('exam_entries')->find($entry));
        $this->assertSame(1, $this->room->exams()->count());
    }

    private function endpoint(): string
    {
        return '/api/v1/admin/rooms/'.$this->room->id.'/status';
    }
}
