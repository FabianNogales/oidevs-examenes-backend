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
        // 1. Preparar base de datos
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        $teacher = User::factory()->create(['status' => 'ACTIVE']);
        DB::table('role_user')->insert(['user_id' => $teacher->id, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Software Engineering', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_user_id' => $teacher->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'AUD-1', 'name' => 'Main Auditorium', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        Sanctum::actingAs($teacher, ['*']);

        // 2. Programar el examen
        $this->postJson("/api/v1/course-offerings/{$courseOfferingId}/exams", [
            'name' => 'Final Exam',
            'exam_date' => now()->addDays(10)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 120,
            'room_id' => $roomId,
            'rules' => 'No devices allowed.'
        ])->assertStatus(201);

        // 3. Verificar que se creó el registro de auditoría (Entity: Exam)
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'action' => 'WRITE',
            'entity_type' => 'Exam'
        ]);
    }
}