<?php

namespace App\Services\Expulsions;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\Student;
use App\Models\User;
use App\Services\Infractions\InfractionAccess;
use Illuminate\Database\Eloquent\Builder;

class ExpulsionAccess
{
    public function __construct(private readonly InfractionAccess $infractions) {}

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
            'Solo el docente responsable puede gestionar expulsiones de este examen.');

        return $exam;
    }

    public function students(Exam $exam): Builder
    {
        return Student::query()->where(function (Builder $query) use ($exam) {
            $query->whereIn('students.id', $this->infractions->students($exam)->select('students.id'))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('expulsions')
                    ->whereColumn('expulsions.student_id', 'students.id')->where('expulsions.exam_id', $exam->id));
        });
    }
}
