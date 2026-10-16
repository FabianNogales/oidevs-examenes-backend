<?php

namespace App\Http\Requests\Entries;

use Illuminate\Foundation\Http\FormRequest;

class ManualEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['room_id' => ['required', 'integer', 'min:1', 'exists:rooms,id']];
    }
}
