<?php

namespace App\Http\Requests\Incidents;

use App\Enums\RoleName;

class IncidentReportRequest extends ListIncidentsRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->isActive()
            && ($actor->hasRole(RoleName::ADMINISTRADOR) || $actor->hasRole(RoleName::DOCENTE));
    }

    public function rules(): array
    {
        $rules = parent::rules() + [
            'exam_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
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
        return parent::messages() + [
            'exam_id.*' => 'Selecciona un identificador de examen válido.',
            'search.*' => 'La búsqueda debe ser texto de hasta 255 caracteres.',
            'status.*' => 'Indica un estado válido usando su código en mayúsculas.',
            'date.*' => 'La fecha debe ser válida y tener el formato AAAA-MM-DD.',
            'date_from.*' => 'La fecha inicial debe ser válida y tener el formato AAAA-MM-DD.',
            'date_to.*' => 'La fecha final debe ser válida y no ser anterior a la inicial.',
        ];
    }
}
