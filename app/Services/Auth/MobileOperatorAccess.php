<?php

namespace App\Services\Auth;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\User;
use App\Services\Collaborators\ExamCollaboratorService;
use App\Services\Exams\ExamManagementPolicy;

class MobileOperatorAccess
{
    public function allows(User $actor): bool
    {
        if (! $actor->isActive()) {
            return false;
        }
        $owned = Exam::whereHas('courseOffering.teacher', fn ($query) => $query->where('user_id', $actor->id))->first();
        if ($owned && app(ExamManagementPolicy::class)->canView($actor, $owned)) {
            return true;
        }

        return ExamCollaborator::with('exam')->where('user_id', $actor->id)
            ->where('status', 'ACTIVE')->whereNull('revoked_at')->get()
            ->contains(fn ($assignment) => $assignment->exam
                && ExamCollaborator::examIsAvailable($assignment->exam)
                && app(ExamCollaboratorService::class)->isCandidate($assignment->exam, $actor->id));
    }
}
