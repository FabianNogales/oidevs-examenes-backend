<?php

namespace App\Services\Attendance;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\User;

class AttendanceAccess
{
    public function exam(int $id, User $actor): Exam
    {
        $actor = User::findOrFail($actor->id);
        $exam = Exam::with('courseOffering.teacher')->findOrFail($id);
        abort_unless($actor->isActive() && $actor->hasRole(RoleName::DOCENTE)
            && (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id, 403,
            'Solo el docente responsable puede supervisar la asistencia de este examen.');

        return $exam;
    }
}
