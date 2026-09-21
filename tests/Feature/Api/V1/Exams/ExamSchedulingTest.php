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

    protected int $ownerUserId;
    protected int $otherUserId;
    protected int $courseOfferingId;
    protected int $roomId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        // Docente Titular
        $ownerTeacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->ownerUserId = $ownerTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->ownerUserId, 'role_id' => $roleId, 'status' => 'ACTIVE']);
        
        $ownerTeacherId = DB::table('teachers')->insertGetId([
            'user_id' => $this->ownerUserId,
            'institutional_code' => 'DOC-001',
            'identity_number' => '12345678',
            'first_names' => 'John',
            'last_names' => 'Doe',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Docente Ajeno (Para IDOR)
        $otherTeacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->otherUserId = $otherTeacher->id;
        DB::table('role_user')->insert(['user_id' => $this->otherUserId, 'role_id' => $roleId, 'status' => 'ACTIVE']);
        
        DB::table('teachers')->insertGetId([
            'user_id' => $this->otherUserId,
            'institutional_code' => 'DOC-002',
            'identity_number' => '87654321',
            'first_names' => 'Jane',
            'last_names' => 'Smith',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert(['id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subjects')->insert(['id' => 1, 'code' => 'CS101', 'name' => 'Software Engineering', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $ownerTeacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->roomId = DB::table('rooms')->insertGetId([
            'code' => 'AUD-1', 'name' => 'Main Auditorium', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);
    }

    public function test_it_validates_required_fields_and_business_rules(): void
    {
        $ownerTeacher = User::find($this->ownerUserId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => '', 
            'exam_date' => now()->subDay()->format('Y-m-d'), 
            'start_time' => '08:00:00',
            'duration_minutes' => -10, 
            'room_id' => 999, 
            'rules' => 'No calculators allowed.'
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'exam_date', 'duration_minutes', 'room_id', 'evaluation_type']);
    }

    public function test_it_prevents_idor_when_scheduling_exam(): void
    {
        $otherTeacher = User::find($this->otherUserId);
        Sanctum::actingAs($otherTeacher, ['*']);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'First Midterm',
            'exam_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.'
        ]);

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - You do not own this course offering.']);
    }

    public function test_teacher_can_schedule_exam_successfully_with_default_status(): void
    {
        $ownerTeacher = User::find($this->ownerUserId);
        Sanctum::actingAs($ownerTeacher, ['*']);

        $futureDate = now()->addDays(5)->format('Y-m-d');

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/exams", [
            'name' => 'First Midterm',
            'exam_date' => $futureDate,
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'room_id' => $this->roomId,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.'
        ]);

        $response->assertStatus(201)
                 ->assertJson(['message' => 'Exam scheduled successfully.']);

        $this->assertDatabaseHas('exams', [
            'course_offering_id' => $this->courseOfferingId,
            'room_id' => $this->roomId,
            'name' => 'First Midterm',
            'exam_date' => $futureDate,
            'duration_minutes' => 90,
            'evaluation_type' => 'partial',
            'rules' => 'Standard rules apply.',
            'status' => 'SCHEDULED'
        ]);
    }
}