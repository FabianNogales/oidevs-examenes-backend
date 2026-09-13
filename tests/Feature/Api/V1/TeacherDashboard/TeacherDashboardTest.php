<?php

namespace Tests\Feature\Api\V1\TeacherDashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected int $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::table('academic_terms')->insert([
            'id' => 1,
            'name' => '2026-I',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('subjects')->insert([
            'id' => 1,
            'code' => 'CS101',
            'name' => 'Introduction to Programming',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function testTeacherCanGetAssignedSubjectsAndPreventsIdor(): void
    {
        $teacherA = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacherB = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);

        DB::table('role_user')->insert([
            ['user_id' => $teacherA->id, 'role_id' => $this->roleId, 'status' => 'ACTIVE', 'assigned_at' => now()],
            ['user_id' => $teacherB->id, 'role_id' => $this->roleId, 'status' => 'ACTIVE', 'assigned_at' => now()]
        ]);

        $teacherAProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherA->id, 'institutional_code' => 'DOC-00A', 'identity_number' => '111', 'first_names' => 'A', 'last_names' => 'A', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        $teacherBProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherB->id, 'institutional_code' => 'DOC-00B', 'identity_number' => '222', 'first_names' => 'B', 'last_names' => 'B', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        DB::table('course_offerings')->insert([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $teacherAProfileId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        Sanctum::actingAs($teacherB, ['*']);
        $response = $this->getJson('/api/v1/teacher/dashboard/subjects');
        
        $response->assertStatus(200)->assertJsonCount(0, 'data');

        Sanctum::actingAs($teacherA, ['*']);
        $responseA = $this->getJson('/api/v1/teacher/dashboard/subjects');

        $responseA->assertStatus(200)
                  ->assertJsonCount(1, 'data')
                  ->assertJsonPath('data.0.subject.name', 'Introduction to Programming')
                  ->assertJsonPath('data.0.academic_term.name', '2026-I');
    }

    public function testTeacherCanGetUpcomingExams(): void
    {
        $teacher = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        
        DB::table('role_user')->insert([
            'user_id' => $teacher->id, 'role_id' => $this->roleId, 'status' => 'ACTIVE', 'assigned_at' => now()
        ]);

        $teacherProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $teacher->id, 'institutional_code' => 'DOC-00X', 'identity_number' => '999', 'first_names' => 'X', 'last_names' => 'X', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        $courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $teacherProfileId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('rooms')->insert([
            'id' => 1, 'code' => 'AUD-1', 'name' => 'Main Auditorium', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()
        ]);

        DB::table('exams')->insert([
            'course_offering_id' => $courseOfferingId,
            'room_id' => 1,
            'name' => 'First Midterm',
            'exam_date' => now()->addDays(5)->format('Y-m-d'),
            'start_time' => '10:00:00',
            'duration_minutes' => 90,
            'status' => 'SCHEDULED',
            'created_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        Sanctum::actingAs($teacher, ['*']);

        $response = $this->getJson('/api/v1/teacher/dashboard/upcoming-exams');

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.name', 'First Midterm')
                 ->assertJsonPath('data.0.course_offering.subject.code', 'CS101');
    }
}