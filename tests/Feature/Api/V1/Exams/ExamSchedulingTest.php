<?php

namespace Tests\Feature\Api\V1\Exams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected int $ownerTeacherId;
    protected int $otherTeacherId;
    protected int $courseOfferingId;
    protected int $roomId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Configurar Roles
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        // 2. Docente Titular (Dueño de la materia)
        $ownerTeacher = User::factory()->create(['status' => 'ACTIVE']);
        $this->ownerTeacherId = $ownerTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->ownerTeacherId, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        // 3. Docente Ajeno (Para probar vulnerabilidad IDOR)
        $otherTeacher = User::factory()->create(['status' => 'ACTIVE']);
        $this->otherTeacherId = $otherTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->otherTeacherId, 'role_id' => $roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]);

        // 4. Base Académica y Oferta de Materia
        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Software Engineering', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_user_id' => $this->ownerTeacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Ambiente institucional
        $this->roomId = DB::table('rooms')->insertGetId([
            'code' => 'AUD-1', 'name' => 'Main Auditorium', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);
    }

    public function test_it_validates_required_fields_and_business_rules(): void
    {
        $ownerTeacher = User::find($this->ownerTeacherId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        // Enviamos fecha en el pasado, duración inválida y ambiente inexistente
        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => '', // Obligatorio vacío
            'exam_date' => now()->subDay()->format('Y-m-d'), // Fecha pasada
            'start_time' => '08:00:00',
            'duration_minutes' => -10, // Menor a cero
            'room_id' => 999, // No existe
            'rules' => 'No calculators allowed.'
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'exam_date', 'duration_minutes', 'room_id']);
    }

    public function test_it_prevents_idor_when_scheduling_exam(): void
    {
        // Autenticamos al docente ajeno intentando programar en la materia del titular
        $otherTeacher = User::find($this->otherTeacherId);
        Sanctum::actingAs($otherTeacher, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'First Midterm',
            'exam_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'rules' => 'Standard rules apply.'
        ]);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - You do not own this course offering.']);
    }

    public function test_teacher_can_schedule_exam_successfully_with_default_status(): void
    {
        $ownerTeacher = User::find($this->ownerTeacherId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        $futureDate = now()->addDays(5)->format('Y-m-d');

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'First Midterm',
            'exam_date' => $futureDate,
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'rules' => 'Standard rules apply.'
        ]);

        $response->assertStatus(201)
                 ->assertJson(['message' => 'Exam scheduled successfully.']);

        // Verificamos que se guardó en BD con el estado inicial automático 'SCHEDULED'
        $this->assertDatabaseHas('exams', [
            'course_offering_id' => $this->courseOfferingId,
            'room_id' => $this->roomId,
            'name' => 'First Midterm',
            'exam_date' => $futureDate,
            'duration_minutes' => 90,
            'rules' => 'Standard rules apply.',
            'status' => 'SCHEDULED'
        ]);
    }
}