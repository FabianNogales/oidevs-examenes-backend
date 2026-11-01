<?php

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', Rule::enum(IncidentType::class)],
            'student_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.*' => 'La página debe ser un entero positivo válido.',
            'per_page.*' => 'La cantidad por página debe estar entre 1 y 100.',
            'type.*' => 'Selecciona un tipo de incidencia válido.',
            'student_id.*' => 'El identificador del estudiante debe ser un entero positivo.',
        ];
    }
}
