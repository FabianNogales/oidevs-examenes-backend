<?php

namespace App\Http\Resources\Expulsions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpulsionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'exam_id' => $this->exam_id, 'student_id' => $this->student_id,
            'infraction_id' => $this->infraction_id, 'reason' => $this->reason,
            'expelled_at' => $this->expelled_at->toISOString(), 'expelled_by' => $this->expelled_by,
            'condition' => 'EXPELLED',
            'student' => $this->student?->only(['id', 'first_names', 'last_names', 'sis_code', 'identity_number']),
            'exam' => $this->exam?->only(['id', 'name', 'exam_date']),
            'infraction' => $this->infraction?->only(['id', 'type', 'status', 'description']),
            'responsible' => ['id' => $this->expelled_by, 'name' => $this->responsible?->display_name]];
    }
}
