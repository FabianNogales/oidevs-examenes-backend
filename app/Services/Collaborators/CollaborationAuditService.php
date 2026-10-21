<?php

namespace App\Services\Collaborators;

use App\Models\AuditLog;
use App\Models\ExamCollaborator;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;

class CollaborationAuditService
{
    public const ASSIGNED = 'EXAM_COLLABORATOR_ASSIGNED';

    public const REASSIGNED = 'EXAM_COLLABORATOR_REASSIGNED';

    public const REVOKED = 'EXAM_COLLABORATOR_REVOKED';

    public function __construct(private AuditLogService $audit) {}

    public function snapshot(ExamCollaborator $collaborator): array
    {
        return ['exam_id' => (int) $collaborator->exam_id, 'collaborator_user_id' => (int) $collaborator->user_id,
            'status' => $collaborator->status, 'assigned_by' => (int) $collaborator->assigned_by,
            'assigned_at' => $collaborator->assigned_at?->toISOString(),
            'revoked_at' => $collaborator->revoked_at?->toISOString()];
    }

    public function open(Request $request, int $actorId, ExamCollaborator $collaborator, ?array $before): AuditLog
    {
        // This immutable event's persisted id IS the episode id; no self-update is needed.
        return $this->audit->log($request, $actorId, $before === null ? self::ASSIGNED : self::REASSIGNED,
            'ExamCollaborator', $collaborator->id, $before, $this->snapshot($collaborator));
    }

    public function openingId(ExamCollaborator $collaborator): ?int
    {
        // A closed or mismatched latest transition is not evidence of an open episode.
        $latest = AuditLog::where('entity_type', 'ExamCollaborator')->where('entity_id', $collaborator->id)
            ->whereIn('action', [self::ASSIGNED, self::REASSIGNED, self::REVOKED])->orderByDesc('id')->first();
        $snapshot = $this->snapshot($collaborator);
        $values = $latest?->new_values;
        if (! $latest || ! in_array($latest->action, [self::ASSIGNED, self::REASSIGNED], true)
            || ! is_array($values) || count($values) !== count($snapshot)) {
            return null;
        }
        // PostgreSQL jsonb does not preserve object-key order.
        foreach ($snapshot as $key => $value) {
            if (! array_key_exists($key, $values) || $values[$key] !== $value) {
                return null;
            }
        }

        return (int) $latest->id;
    }

    public function close(Request $request, int $actorId, ExamCollaborator $collaborator, array $before, ?int $openingId): AuditLog
    {
        return $this->audit->log($request, $actorId, self::REVOKED, 'ExamCollaborator', $collaborator->id,
            $before, $this->snapshot($collaborator) + [
                'opening_event_id' => $openingId,
                'history_complete' => $openingId !== null,
            ]);
    }
}
