<?php

namespace App\Http\Resources\Audit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'actor_id' => $this->user_id, 'action' => $this->action,
            'entity_type' => $this->entity_type, 'entity_id' => $this->entity_id,
            'created_at' => $this->created_at?->toISOString(),
            'old_context' => $this->context($this->old_values), 'new_context' => $this->context($this->new_values)];
    }

    /** An allowlist, not a denylist: historic/free-form snapshots never become public JSON. */
    private function context(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $safe = [];
        foreach (['course_offering_id', 'exam_id', 'qr_exam_id', 'student_id', 'room_id',
            'collaborator_user_id', 'assigned_by', 'opening_event_id', 'duration_minutes'] as $key) {
            if (isset($values[$key]) && is_int($values[$key]) && $values[$key] >= 0) {
                $safe[$key] = $values[$key];
            }
        }
        $enums = ['source' => ['APPLICATION'], 'outcome' => ['CONFIRMED'], 'mode' => ['MANUAL', 'CSV'],
            'scope' => ['ENROLLMENT', 'COURSE_OFFERING'], 'method' => ['MANUAL', 'QR'], 'reason' => ['WRONG_EXAM'],
            'operation' => ['ENROLLMENT_CREATED', 'ENROLLMENTS_IMPORTED', 'EXAM_SCHEDULED'],
            'status' => ['ACTIVE', 'INACTIVE', 'ELIGIBLE', 'INELIGIBLE', 'SCHEDULED', 'RESCHEDULED', 'CANCELLED'],
            'evaluation_type' => ['partial', 'final', 'makeup']];
        foreach ($enums as $key => $allowed) {
            if (isset($values[$key]) && in_array($values[$key], $allowed, true)) {
                $safe[$key] = $values[$key];
            }
        }
        if (isset($values['history_complete']) && is_bool($values['history_complete'])) {
            $safe['history_complete'] = $values['history_complete'];
        }
        $dates = ['assigned_at' => '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/',
            'revoked_at' => '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/',
            'exam_date' => '/\A\d{4}-\d{2}-\d{2}\z/', 'start_time' => '/\A\d{2}:\d{2}(?::\d{2})?\z/'];
        foreach ($dates as $key => $pattern) {
            if (isset($values[$key]) && is_string($values[$key]) &&
                preg_match($pattern, $values[$key])) {
                $safe[$key] = $values[$key];
            }
        }
        if (isset($values['summary']) && is_array($values['summary'])) {
            foreach (['totalProcessed', 'successfulRecords', 'duplicateRecords', 'failedCount'] as $key) {
                $count = $values['summary'][$key] ?? null;
                if (is_int($count) && $count >= 0) {
                    $safe['summary'][$key] = $count;
                }
            }
        }

        return $safe;
    }
}
