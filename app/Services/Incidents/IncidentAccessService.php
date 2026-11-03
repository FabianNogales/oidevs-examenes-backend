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
        $permissions = $this->permissions($exam, $actor, $lockAssignment);
        abort_unless($permissions['can_create'], 403,
            'No tiene autorización vigente para registrar incidencias de este examen.');
    }

    public function permissions(Exam $exam, User $actor, bool $lockAssignment = false): array
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

        $admin = $actor->hasRole(RoleName::ADMINISTRADOR);
        abort_unless($actor->isActive() && ($owner || $collaborator || $admin), 403,
            'No tiene autorización vigente para acceder a las incidencias de este examen.');

        return ['can_create' => $owner || $collaborator, 'can_export' => $owner || $admin];
    }
}
