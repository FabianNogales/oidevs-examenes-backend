<?php

namespace Tests\Feature\Api\V1\Exams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_exam_generates_audit_log(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        $teacherUser = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        DB::table('role_user')->insert(['user_id' => $teacherUser->id, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherUser->id,
            'institutional_code' => 'DOC-003',
            'identity_number' => '99999999',
            'first_names' => 'Carlos',
            'last_names' => 'Perez',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE']);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Software Engineering', 'status' => 'ACTIVE']);

        $courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'AUD-1', 'name' => 'Main Auditorium', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        Sanctum::actingAs($teacherUser, ['*']);

        $this->postJson("/api/v1/course-offerings/{$courseOfferingId}/exams", [
            'name' => 'Final Exam',
            'exam_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 120,
            'room_id' => $roomId,
            'rules' => 'No devices allowed.'
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacherUser->id,
            'action' => 'WRITE',
            'entity_type' => 'Exam'
        ]);
    }
}