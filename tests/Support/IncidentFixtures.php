<?php

namespace Tests\Support;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait IncidentFixtures
{
    protected User $owner;

    protected Exam $exam;

    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'DOCENTE'], ['status' => 'ACTIVE']);
        $this->owner->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $teacher = Teacher::create(['user_id' => $this->owner->id, 'institutional_code' => 'INC-01',
            'identity_number' => '987654', 'first_names' => 'Docente', 'last_names' => 'Responsable', 'status' => 'ACTIVE']);
        $subject = DB::table('subjects')->insertGetId(['code' => 'INC', 'name' => 'Materia', 'status' => 'ACTIVE']);
        $term = DB::table('academic_terms')->insertGetId(['name' => 'Incidencias',
            'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'status' => 'ACTIVE']);
        $offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject,
            'teacher_id' => $teacher->id, 'academic_term_id' => $term, 'status' => 'ACTIVE']);
        $room = DB::table('rooms')->insertGetId(['code' => 'INC', 'name' => 'Aula de incidencias', 'status' => 'ACTIVE']);
        $this->exam = Exam::create(['course_offering_id' => $offering, 'room_id' => $room,
            'created_by' => $this->owner->id, 'name' => 'Examen', 'exam_date' => now()->addDay()->toDateString(),
            'start_time' => '09:00:00', 'duration_minutes' => 90, 'status' => 'SCHEDULED', 'evaluation_type' => 'PARTIAL']);
        $career = DB::table('careers')->insertGetId(['code' => 'INC', 'name' => 'Carrera', 'status' => 'ACTIVE']);
        $this->student = Student::create(['user_id' => User::factory()->create()->id, 'career_id' => $career,
            'sis_code' => '9876543', 'identity_number' => '1234567', 'first_names' => 'Estudiante', 'last_names' => 'Identificable', 'status' => 'ACTIVE']);
    }

    protected function url(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/incidents';
    }

    protected function collaborator(): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        ExamCollaborator::create(['exam_id' => $this->exam->id, 'user_id' => $user->id,
            'assigned_by' => $this->owner->id, 'assigned_at' => now(), 'status' => 'ACTIVE', 'access_code_hash' => hash('sha256', 'test')]);

        return $user;
    }
}
