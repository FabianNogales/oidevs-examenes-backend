<?php

namespace App\Http\Requests\Entries;

use Illuminate\Foundation\Http\FormRequest;

class QrEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'max:4096'],
            'room_id' => ['required', 'integer', 'min:1', 'exists:rooms,id'],
        ];
    }
}
