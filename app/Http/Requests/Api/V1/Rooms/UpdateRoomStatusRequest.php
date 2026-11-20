<?php

namespace App\Http\Requests\Api\V1\Rooms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['ACTIVE', 'INACTIVE'])],
            'inactive_until' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'after:now', Rule::prohibitedIf(fn () => $this->input('status') !== 'INACTIVE')],
        ];
    }
}
