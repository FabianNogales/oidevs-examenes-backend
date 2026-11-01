<?php

namespace App\Services\Incidents;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\User;
use App\Services\Collaborators\ExamCollaboratorService;

class IncidentAccessService
{
    public function authorize(Exam $exam, User $actor, bool $lockAssignment = false): void
    {
        $query = ExamCollaborator::query()->where('exam_id', $exam->id)->where('user_id', $actor->id);
        if ($lockAssignment) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        $owner = (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id
            && $actor->hasRole(RoleName::DOCENTE);
        $collaborator = $assignment?->status === 'ACTIVE' && $assignment->revoked_at === null
            && ExamCollaborator::examIsAvailable($exam)
            && app(ExamCollaboratorService::class)->isCandidate($exam, $actor->id);

        abort_unless($actor->isActive() && ($owner || $collaborator), 403,
            'No tiene autorización vigente para acceder a las incidencias de este examen.');
    }
}
