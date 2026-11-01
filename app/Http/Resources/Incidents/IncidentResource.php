<?php

namespace App\Http\Resources\Incidents;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'type' => $this->type->value,
            'description' => $this->description,
            'origin' => 'MANUAL',
            'reported_by' => $this->reported_by,
            'reported_at' => $this->created_at->toISOString(),
            'status' => $this->status,
        ];
    }
}
