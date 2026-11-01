<?php

namespace App\Services\Infractions;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InfractionAccess
{
    public function exam(int $examId, User $actor, bool $lock = false): Exam
    {
        $query = Exam::with('courseOffering.teacher');
        if ($lock) {
            $query->lockForUpdate();
        }
        $exam = $query->findOrFail($examId);
        $users = User::query();
        if ($lock) {
            $users->lock('FOR NO KEY UPDATE');
        }
        $actor = $users->findOrFail($actor->id);
        abort_unless($actor->isActive() && $actor->hasRole(RoleName::DOCENTE)
            && (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id, 403,
            'Solo el docente responsable puede gestionar infracciones de este examen.');

        return $exam;
    }

    public function students(Exam $exam): Builder
    {
        return Student::query()->where(function (Builder $query) use ($exam) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('enrollments')
                ->whereColumn('enrollments.student_id', 'students.id')->where('course_offering_id', $exam->course_offering_id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('exam_eligibilities')
                    ->whereColumn('exam_eligibilities.student_id', 'students.id')->where('exam_id', $exam->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('exam_entries')
                    ->whereColumn('exam_entries.student_id', 'students.id')->where('exam_id', $exam->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('infractions')
                    ->whereColumn('infractions.student_id', 'students.id')->where('exam_id', $exam->id));
        });
    }

    public function assertStudent(Exam $exam, int $studentId): void
    {
        if (! $this->students($exam)->whereKey($studentId)->exists()) {
            throw ValidationException::withMessages(['student_id' => 'El estudiante no está relacionado con este examen.']);
        }
    }
}
