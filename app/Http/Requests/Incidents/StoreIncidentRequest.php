<?php

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Exam ownership and temporary collaborator access belong to the endpoint/service.
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') === 'WRONG_EXAM') {
            $this->merge(['type' => 'WRONG_EXAM_QR']);
        }
        if (is_string($this->input('description'))) {
            $this->merge(['description' => trim($this->input('description'))]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(IncidentType::class)],
            'description' => ['required', 'string'],
            'student_id' => ['nullable', 'integer', 'min:1', 'exists:students,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['type', 'description', 'student_id']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en el registro de incidencias.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Selecciona el tipo de incidencia.',
            'type.enum' => 'Selecciona un tipo de incidencia válido.',
            'description.required' => 'Describe lo sucedido.',
            'description.string' => 'La descripción debe ser texto.',
            'student_id.integer' => 'El identificador del estudiante debe ser un entero.',
            'student_id.min' => 'El identificador del estudiante debe ser positivo.',
            'student_id.exists' => 'El estudiante indicado no existe.',
        ];
    }
}
