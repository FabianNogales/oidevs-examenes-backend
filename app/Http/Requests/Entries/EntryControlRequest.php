<?php

namespace App\Http\Requests\Entries;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntryControlRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('has_incident') === 'true' || $this->input('has_incident') === 'false') {
            $this->merge(['has_incident' => $this->input('has_incident') === 'true']);
        }
    }

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
            'entry_status' => ['sometimes', Rule::in(['REGISTERED', 'PENDING'])],
            'has_incident' => ['sometimes', 'boolean'],
        ];
    }
}
