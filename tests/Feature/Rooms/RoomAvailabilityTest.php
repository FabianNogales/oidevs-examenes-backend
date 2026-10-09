<?php

namespace Tests\Feature\Rooms;

use App\Models\Role;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        foreach (['DOCENTE', 'ADMINISTRADOR'] as $name) {
            $role = Role::create(['name' => $name, 'status' => 'ACTIVE']);
            $this->user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        }
        $teacher = Teacher::factory()->create(['user_id' => $this->user->id]);
        $subject = DB::table('subjects')->insertGetId(['code' => 'CS101', 'name' => 'Programación', 'status' => 'ACTIVE']);
        $term = DB::table('academic_terms')->insertGetId(['name' => '2026-II', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE']);
        $this->offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject, 'academic_term_id' => $term, 'teacher_id' => $teacher->id, 'status' => 'ACTIVE']);
        $this->room = Room::create(['code' => 'A-01', 'name' => 'Principal', 'status' => 'ACTIVE']);
        Sanctum::actingAs($this->user);
    }

    public function test_current_occupation_uses_institutional_timezone_and_end_is_exclusive(): void
    {
        $exam = $this->exam('2026-10-11', '23:30:00', 90);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 00:15:00', 'America/La_Paz'));
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertOk()
            ->assertJsonPath('data.availability', 'OCCUPIED')->assertJsonPath('data.current_exam.id', $exam)
            ->assertJsonPath('data.current_exam.end_time', '01:00:00');
        $this->getJson('/api/v1/admin/rooms')->assertJsonPath('data.0.availability', 'OCCUPIED');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 01:00:00', 'America/La_Paz'));
        $this->getJson('/api/v1/admin/rooms/'.$this->room->id)->assertJsonPath('data.availability', 'AVAILABLE')
            ->assertJsonPath('data.current_exam', null);
        $this->travelBack();
    }

    public function test_query_excludes_inactive_and_overlaps_but_allows_adjacent_intervals(): void
    {
        $this->exam('2026-10-11', '10:00:00', 90);
        Room::create(['code' => 'OFF', 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/rooms')->assertOk()->assertJsonCount(1, 'data');
        foreach ([['09:30', 31], ['10:00', 90], ['10:30', 15], ['09:00', 240]] as [$time, $duration]) {
            $this->query('2026-10-11', $time, $duration)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->query('2026-10-11', '09:00', 60)->assertJsonCount(1, 'data');
        $this->query('2026-10-11', '11:30:00', 60)->assertJsonCount(1, 'data');
        $this->query('2026-10-12', '10:00', 90)->assertJsonCount(1, 'data');
    }

    public function test_cross_midnight_conflicts_and_cancelled_exams(): void
    {
        $id = $this->exam('2026-10-11', '23:30:00', 120);
        $this->query('2026-10-12', '00:30', 30)->assertJsonCount(0, 'data');
        $this->query('2026-10-12', '01:30', 30)->assertJsonCount(1, 'data');
        DB::table('exams')->where('id', $id)->update(['status' => 'CANCELLED']);
        $this->query('2026-10-12', '00:30', 30)->assertJsonCount(1, 'data');
    }

    public function test_invalid_or_partial_interval_is_rejected(): void
    {
        foreach (['exam_date=2026-10-11', 'start_time=10:00', 'duration_minutes=90',
            'exam_date=2026-02-30&start_time=10:00&duration_minutes=90',
            'exam_date=2026-10-11&start_time=25:00&duration_minutes=90',
            'exam_date=2026-10-11&start_time=10:00&duration_minutes=0'] as $query) {
            $this->getJson('/api/v1/rooms?'.$query)->assertUnprocessable();
        }
    }

    public function test_scheduling_rechecks_overlap_and_inactive_state_without_side_effects(): void
    {
        $date = now('America/La_Paz')->addDays(5)->toDateString();
        $this->exam($date, '10:00:00', 90);
        $audits = DB::table('audit_logs')->count();
        $payload = ['name' => 'Nuevo parcial', 'room_id' => $this->room->id, 'exam_date' => $date,
            'start_time' => '10:30:00', 'duration_minutes' => 30, 'evaluation_type' => 'partial'];
        $endpoint = '/api/v1/course-offerings/'.$this->offering.'/exams';
        $this->postJson($endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->assertDatabaseCount('exams', 1);
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->room->update(['status' => 'INACTIVE']);
        $payload['start_time'] = '11:30:00';
        $this->postJson($endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->room->update(['status' => 'ACTIVE']);
        $this->postJson($endpoint, $payload)->assertCreated();
        $this->assertDatabaseCount('exams', 2);
    }

    public function test_scheduling_rejects_invalid_dates_without_server_error(): void
    {
        $this->postJson('/api/v1/course-offerings/'.$this->offering.'/exams', [
            'name' => 'Parcial', 'room_id' => $this->room->id, 'exam_date' => 'invalid-date',
            'start_time' => '10:00:00', 'duration_minutes' => 60, 'evaluation_type' => 'partial',
        ])->assertUnprocessable()->assertJsonValidationErrors('exam_date');
    }

    private function exam(string $date, string $time, int $duration): int
    {
        return DB::table('exams')->insertGetId(['course_offering_id' => $this->offering, 'room_id' => $this->room->id,
            'name' => 'Parcial', 'exam_date' => $date, 'start_time' => $time, 'duration_minutes' => $duration,
            'status' => 'SCHEDULED', 'evaluation_type' => 'partial', 'created_by' => $this->user->id]);
    }

    private function query(string $date, string $time, int $duration)
    {
        return $this->getJson('/api/v1/rooms?'.http_build_query(['exam_date' => $date, 'start_time' => $time, 'duration_minutes' => $duration]));
    }
}
