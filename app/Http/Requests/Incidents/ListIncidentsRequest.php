<?php

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIncidentsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', Rule::in([...array_column(IncidentType::cases(), 'value'), 'WRONG_EXAM'])],
            'student_id' => ['sometimes', 'integer', 'min:1'],
            'origin' => ['sometimes', Rule::in(['MANUAL', 'AUTOMATIC_QR', 'AUTOMATIC'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[A-Z][A-Z_]*$/'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
        if ($this->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'page.*' => 'La página debe ser un entero positivo válido.',
            'per_page.*' => 'La cantidad por página debe estar entre 1 y 100.',
            'type.*' => 'Selecciona un tipo de incidencia válido.',
            'student_id.*' => 'El identificador del estudiante debe ser un entero positivo.',
            'origin.*' => 'El origen debe ser MANUAL o AUTOMATIC_QR.',
        ];
    }
}
