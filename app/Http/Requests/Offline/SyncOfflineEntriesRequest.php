<?php

namespace App\Http\Requests\Offline;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncOfflineEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    public function rules(): array
    {
        $v2 = (int) $this->input('protocol_version', 1) === 2;
        return [
            'protocol_version' => ['sometimes', 'integer', Rule::in([1, 2])],
            'package_id' => ['required', 'uuid'],
            'list_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'room_id' => ['required', 'integer', 'min:1'],
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*' => ['required', 'array:event_id,student_id,method,captured_at,device_id,qr_token'.($v2 ? ',time_evidence' : '')],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.student_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'events.*.method' => ['required', Rule::in(['MANUAL', 'QR'])],
            'events.*.captured_at' => ['required', 'string', 'max:40', 'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'events.*.device_id' => ['nullable', 'string', 'max:100'],
            // Missing QR evidence is an individual rejection, not a failure of the whole batch.
            'events.*.qr_token' => ['nullable', 'string', 'max:4096'],
            'events.*.time_evidence' => [$v2 ? 'required' : 'prohibited',
                'array:serverMs,boot,elapsed,capturedElapsed,source'],
            'events.*.time_evidence.serverMs' => [$v2 ? 'required' : 'prohibited', 'integer', 'min:0', 'max:9007199254740991'],
            'events.*.time_evidence.boot' => [$v2 ? 'required' : 'prohibited', 'string', 'max:40', 'regex:/^[0-9]+$/D'],
            'events.*.time_evidence.elapsed' => [$v2 ? 'required' : 'prohibited', 'integer', 'min:0', 'max:9007199254740991'],
            'events.*.time_evidence.capturedElapsed' => [$v2 ? 'required' : 'prohibited', 'integer', 'min:0', 'max:9007199254740991'],
            'events.*.time_evidence.source' => [$v2 ? 'required' : 'prohibited', Rule::in(['SERVER_ANCHORED_ESTIMATE'])],
        ];
    }
}
