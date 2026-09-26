<?php

namespace Tests\Feature\Api\V1\Exams;

use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Exception;

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
            'rules' => 'No devices allowed.',
            'evaluation_type' => 'final'
        ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacherUser->id,
            'action' => 'WRITE',
            'entity_type' => 'Exam'
        ]);
    }

    public function test_audit_logs_are_strictly_immutable(): void
    {
        $user = User::factory()->create();

        $log = AuditLog::create([
            'user_id' => $user->id,
            'action' => 'WRITE',
            'entity_type' => 'Exam',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'created_at' => now(),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Violación de seguridad');
        
        $log->update(['action' => 'TAMPERED_ACTION']);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $log = AuditLog::create([
            'user_id' => $user->id,
            'action' => 'WRITE',
            'entity_type' => 'Exam',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'created_at' => now(),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Violación de seguridad');

        $log->delete();
    }

    public function test_native_postgresql_trigger_audits_exam_creation(): void
    {
        // 1. Preparar dependencias mínimas en la base de datos
        $teacherUser = \App\Models\User::factory()->create();
        
        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherUser->id,
            'institutional_code' => 'DOC-TRIG-01',
            'identity_number' => '88888888',
            'first_names' => 'Trigger',
            'last_names' => 'Test',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insertOrIgnore(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE']);
        DB::table('subjects')->insertOrIgnore(['id' => 1, 'code' => 'CS101', 'name' => 'Software Engineering', 'status' => 'ACTIVE']);

        $courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'AUD-TRIG', 'name' => 'Trigger Room', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        // 2. Insertar directamente usando Query Builder (Bypass de Eloquent/Controladores)
        $examId = DB::table('exams')->insertGetId([
            'name' => 'Native Trigger Exam',
            'course_offering_id' => $courseOfferingId,
            'exam_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '14:00:00',
            'duration_minutes' => 90,
            'room_id' => $roomId,
            'evaluation_type' => 'partial',
            'status' => 'SCHEDULED',
            'created_by' => $teacherUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Verificar que la tabla audit_logs ya tiene el registro generado por PostgreSQL
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'Exam',
            'entity_id' => $examId,
            'action' => 'CREATE'
        ]);
    }
}