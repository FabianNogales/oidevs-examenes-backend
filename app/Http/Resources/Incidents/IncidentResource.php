<?php

namespace App\Http\Resources\Incidents;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->resource;
        $origin = $record->origin ?? 'MANUAL';
        $sourceId = (int) ($record->source_id ?? $record->id);

        return [
            'id' => $origin === 'AUTOMATIC_QR' ? 'qr-'.$sourceId : $sourceId,
            'exam_id' => (int) $this->exam_id,
            'student_id' => $this->student_id === null ? null : (int) $this->student_id,
            'type' => $this->type instanceof BackedEnum ? $this->type->value : $this->type,
            'description' => $this->description,
            'origin' => $origin,
            'reported_by' => $this->reported_by === null ? null : (int) $this->reported_by,
            'reported_at' => CarbonImmutable::parse($this->created_at, config('app.timezone'))->toISOString(),
            'status' => $this->status,
            'source_event_id' => $this->when($origin === 'AUTOMATIC_QR', $sourceId),
            'reason' => $this->when($origin === 'AUTOMATIC_QR', 'WRONG_EXAM'),
            'qr_exam_id' => $this->when($origin === 'AUTOMATIC_QR',
                isset($record->qr_exam_id) ? (int) $record->qr_exam_id : null),
        ];
    }
}
