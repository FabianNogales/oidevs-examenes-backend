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
        return [
            'package_id' => ['required', 'uuid'],
            'list_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'room_id' => ['required', 'integer', 'min:1'],
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*' => ['required', 'array:event_id,student_id,method,captured_at,device_id,qr_token'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.student_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'events.*.method' => ['required', Rule::in(['MANUAL', 'QR'])],
            'events.*.captured_at' => ['required', 'string', 'max:40', 'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'events.*.device_id' => ['nullable', 'string', 'max:100'],
            // Missing QR evidence is an individual rejection, not a failure of the whole batch.
            'events.*.qr_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
