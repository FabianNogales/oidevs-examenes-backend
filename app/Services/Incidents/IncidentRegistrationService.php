<?php

namespace App\Services\Incidents;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\SpecialCase;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Collaborators\ExamCollaboratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncidentRegistrationService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function register(int $examId, array $data, Request $request): SpecialCase
    {
        return DB::transaction(function () use ($examId, $data, $request) {
            // Match the exam-first lock order used by entry control and collaborator changes.
            $exam = Exam::query()->lockForUpdate()->findOrFail($examId);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $assignment = ExamCollaborator::query()->where('exam_id', $exam->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            $owner = (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id
                && $actor->hasRole(RoleName::DOCENTE);
            $collaborator = $assignment?->status === 'ACTIVE' && $assignment->revoked_at === null
                && ExamCollaborator::examIsAvailable($exam)
                && app(ExamCollaboratorService::class)->isCandidate($exam, $actor->id);

            abort_unless($actor->isActive() && ($owner || $collaborator), 403,
                'No tiene autorización vigente para registrar incidencias de este examen.');

            $incident = SpecialCase::query()->create([
                'exam_id' => $exam->id,
                'student_id' => $data['student_id'] ?? null,
                'type' => $data['type'],
                'description' => $data['description'],
                'reported_by' => $actor->id,
                // A report is pending review, never a confirmed disciplinary infraction.
                'status' => 'PENDING',
            ]);

            $this->audit->log($request, $actor->id, 'INCIDENT_REPORTED', 'SpecialCase', $incident->id,
                newValues: [
                    'exam_id' => $incident->exam_id, 'student_id' => $incident->student_id,
                    'type' => $incident->type->value, 'description' => $incident->description,
                    'origin' => 'MANUAL', 'status' => $incident->status,
                ]);

            return $incident;
        });
    }
}
