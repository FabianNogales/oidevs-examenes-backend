<?php

namespace App\Http\Requests\Entries;

use Illuminate\Foundation\Http\FormRequest;

class EntryControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // verify.exam.access comprueba el examen antes de validar parámetros.
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'room_id' => ['sometimes', 'integer', 'min:1', 'exists:rooms,id'],
        ];
    }
}
