<?php

namespace App\Http\Resources\Incidents;

use Illuminate\Http\Request;

class IncidentReportResource extends IncidentResource
{
    public function toArray(Request $request): array
    {
        return array_replace(parent::toArray($request), [
            // HU29 keeps its established report identifiers and export columns.
            'id' => $this->origin === 'AUTOMATIC_QR' ? 'qr-'.$this->source_id : (int) $this->source_id,
            'origin' => $this->origin,
            'type' => $this->type,
            'status' => $this->status,
            'reported_by' => $this->reported_by === null ? null : (int) $this->reported_by,
            'reason' => $this->origin === 'AUTOMATIC_QR' ? 'WRONG_EXAM' : $this->description,
            'exam' => ['id' => (int) $this->exam_id, 'name' => $this->exam_name, 'exam_date' => $this->exam_date],
            'student' => $this->student_id === null ? null : [
                'id' => (int) $this->student_id, 'first_names' => $this->student_first_names,
                'last_names' => $this->student_last_names, 'sis_code' => $this->sis_code,
                'identity_number' => $this->identity_number,
            ],
            'responsible' => $this->reported_by === null ? null : [
                'id' => (int) $this->reported_by, 'name' => $this->reporter_name,
            ],
        ]);
    }
}
