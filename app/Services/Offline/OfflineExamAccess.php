<?php

namespace App\Services\Offline;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\User;
use App\Services\Collaborators\ExamCollaboratorService;
use App\Services\Exams\ExamManagementPolicy;
use Illuminate\Http\Exceptions\HttpResponseException;

class OfflineExamAccess
{
    public function owner(Exam $exam, User $actor): bool
    {
        return app(ExamManagementPolicy::class)->canView($actor, $exam);
    }

    public function authorizeOwner(Exam $exam, User $actor): void
    {
        abort_unless($this->owner($exam, $actor), 403, 'Solo el docente responsable puede consultar o preparar la distribución.');
    }

    public function authorizationHash(Exam $exam, User $actor): ?string
    {
        if ($this->owner($exam, $actor)) {
            return hash('sha256', 'owner:'.$exam->course_offering_id.':'.$actor->id);
        }
        if (! $actor->isActive() || ! app(ExamCollaboratorService::class)->isCandidate($exam, $actor->id)) {
            return null;
        }
        $collaborator = ExamCollaborator::where('exam_id', $exam->id)->where('user_id', $actor->id)
            ->where('status', 'ACTIVE')->first();

        // The rotating credential binds the list to this authorization episode, including legacy assignments.
        return $collaborator ? hash('sha256', 'collaborator:'.$collaborator->id.':'.$collaborator->access_code_hash) : null;
    }

    public function examHash(Exam $exam): string
    {
        return hash('sha256', json_encode($exam->only(['id', 'course_offering_id', 'room_id', 'exam_date',
            'start_time', 'duration_minutes', 'name', 'evaluation_type', 'rules', 'updated_at']), JSON_THROW_ON_ERROR));
    }

    public function fail(string $code, string $message, int $status = 409): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
