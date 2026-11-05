<?php

namespace App\Http\Resources\Infractions;

use App\Services\Infractions\InfractionRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfractionResource extends JsonResource
{
    public bool $readOnly = false;

    public function toArray(Request $request): array
    {
        $allowed = $this->readOnly ? [] : match ($this->status) {
            'REPORTED' => ['CONFIRMED', 'RESOLVED'], 'CONFIRMED' => ['RESOLVED'], default => [],
        };

        return [
            'id' => $this->id, 'exam_id' => $this->exam_id, 'student_id' => $this->student_id,
            'type' => $this->type, 'description' => $this->description, 'observations' => $this->observations,
            'status' => $this->status, 'reported_by' => ['id' => (int) $this->reported_by, 'name' => $this->reporter?->display_name],
            'occurred_at' => $this->occurred_at->toISOString(), 'updated_at' => InfractionRevision::current($this->resource)->toISOString(),
            'revision' => (int) $this->revision,
            'student' => $this->student?->only(['id', 'first_names', 'last_names', 'sis_code', 'identity_number']),
            'exam' => $this->exam?->only(['id', 'name', 'exam_date']),
            'responsible' => ['id' => $this->reported_by, 'name' => $this->reporter?->display_name],
            'can_edit' => ! $this->readOnly && in_array($this->status, ['REPORTED', 'CONFIRMED'], true),
            'allowed_statuses' => $allowed,
            'permissions' => ['can_update' => $allowed !== [], 'allowed_statuses' => $allowed],
        ];
    }
}
