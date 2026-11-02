<?php

namespace App\Http\Resources\Incidents;

use App\Models\SpecialCase;
use App\Services\Incidents\IncidentIdentity;
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
        $automatic = $origin === 'AUTOMATIC_QR';
        $student = $record instanceof SpecialCase ? $record->student : null;
        $reporter = $record instanceof SpecialCase ? $record->reporter : null;
        $type = $this->type instanceof BackedEnum ? $this->type->value : $this->type;
        $createdAt = CarbonImmutable::parse($this->created_at, config('app.timezone'))->toISOString();

        return [
            'id' => $automatic ? IncidentIdentity::automatic($sourceId) : $sourceId,
            'exam_id' => (int) $this->exam_id,
            'student_id' => $this->student_id === null ? null : (int) $this->student_id,
            'type' => $type === 'WRONG_EXAM_QR' ? 'WRONG_EXAM' : $type,
            'description' => $this->description,
            'origin' => $automatic ? 'AUTOMATIC' : 'MANUAL',
            'student' => $this->student_id === null || ($student === null && ! isset($record->student_first_names)) ? null : [
                'id' => (int) $this->student_id,
                'first_names' => $student?->first_names ?? $record->student_first_names,
                'last_names' => $student?->last_names ?? $record->student_last_names,
                'sis_code' => (string) ($student?->sis_code ?? $record->sis_code),
                'identity_number' => $student?->identity_number ?? ($record->identity_number ?? null),
            ],
            'reported_by' => $this->reported_by === null ? null : [
                'id' => (int) $this->reported_by,
                'name' => $reporter?->display_name ?? $record->reporter_name,
            ],
            'created_at' => $createdAt,
            'reported_at' => $createdAt,
            'status' => match ($this->status) {
                'PENDING' => 'REPORTED', 'REJECTED' => 'REGISTERED', default => $this->status
            },
            'source_event_id' => $this->when($origin === 'AUTOMATIC_QR', $sourceId),
            'reason' => $this->when($origin === 'AUTOMATIC_QR', 'WRONG_EXAM'),
            'qr_exam_id' => $this->when($origin === 'AUTOMATIC_QR',
                isset($record->qr_exam_id) ? (int) $record->qr_exam_id : null),
        ];
    }
}
