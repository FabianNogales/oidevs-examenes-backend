<?php

namespace Tests\Support;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Role;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait CourseOfferingFixture
{
    private function offeringFixture(): array
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        foreach (['DOCENTE', 'ADMINISTRADOR'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
            $user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        }
        $teacher = Teacher::factory()->create(['user_id' => $user->id]);
        $term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $offering = CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id, 'status' => 'ACTIVE']);
        $room = Room::create(['code' => 'A-01', 'name' => 'Aula 01', 'capacity' => 50, 'status' => 'ACTIVE']);
        $careerId = DB::table('careers')->insertGetId(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $studentId = DB::table('students')->insertGetId(['user_id' => User::factory()->create()->id,
            'sis_code' => '202600001', 'identity_number' => '70000001', 'first_names' => 'Alumno', 'last_names' => 'Prueba', 'career_id' => $careerId, 'status' => 'ACTIVE']);

        return compact('user', 'teacher', 'term', 'subject', 'offering', 'room', 'studentId');
    }

    private function examPayload(int $roomId): array
    {
        return ['room_id' => $roomId, 'name' => 'Parcial', 'exam_date' => now('America/La_Paz')->addDays(5)->toDateString(),
            'start_time' => '10:00:00', 'duration_minutes' => 60, 'evaluation_type' => 'partial', 'rules' => 'Reglas'];
    }
}
