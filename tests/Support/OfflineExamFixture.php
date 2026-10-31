<?php

namespace Tests\Support;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait OfflineExamFixture
{
    use CourseOfferingFixture;

    private array $fixture;

    private Exam $exam;

    private function createOfflineFixture(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:05:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create(array_replace($this->examPayload($this->fixture['room']->id), [
            'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id,
            'exam_date' => '2026-11-02', 'start_time' => '10:00:00', 'status' => 'SCHEDULED',
        ]));
        $this->enrollOfflineStudent($this->fixture['studentId']);
    }

    private function enrollOfflineStudent(int $studentId): void
    {
        DB::table('enrollments')->insert(['student_id' => $studentId, 'course_offering_id' => $this->exam->course_offering_id,
            'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $studentId, 'status' => 'ELIGIBLE']);
    }

    private function addOfflineStudent(string $surname = 'Prueba', string $name = 'Alumno'): Student
    {
        $n = DB::table('students')->count() + 1;
        $student = Student::create(['user_id' => User::factory()->create()->id,
            'career_id' => DB::table('careers')->value('id'), 'sis_code' => sprintf('%09d', $n),
            'identity_number' => 'CI-'.$n, 'first_names' => $name, 'last_names' => $surname, 'status' => 'ACTIVE']);
        $this->enrollOfflineStudent($student->id);

        return $student;
    }

    private function offlineCollaborator(): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        ExamCollaborator::create(['exam_id' => $this->exam->id, 'user_id' => $user->id,
            'assigned_by' => $this->fixture['user']->id, 'status' => 'ACTIVE', 'assigned_at' => now(),
            'access_code_hash' => hash('sha256', Str::random(32))]);

        return $user;
    }

    private function offlineBase(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/offline';
    }

    private function offlineEvent(?int $studentId = null, array $overrides = []): array
    {
        return array_replace(['event_id' => (string) Str::uuid(), 'student_id' => $studentId ?? $this->fixture['studentId'],
            'method' => 'MANUAL', 'captured_at' => now()->toISOString(), 'device_id' => 'device-A'], $overrides);
    }

    private function offlineBatch(array $package, array $events): array
    {
        return ['package_id' => $package['package_id'], 'list_id' => $package['list_id'],
            'version' => $package['version'], 'room_id' => $package['room_id'], 'events' => $events];
    }
}
