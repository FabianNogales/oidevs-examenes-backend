<?php

namespace App\Http\Requests\Infractions;

use App\Enums\InfractionStatus;
use App\Enums\InfractionType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInfractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function isStatusChange(): bool
    {
        return $this->routeIs('infractions.status');
    }

    protected function prepareForValidation(): void
    {
        foreach (['description', 'observations', 'reason'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $this->merge([$key => $key === 'observations' && $value === '' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        if ($this->isStatusChange()) {
            return ['status' => ['required', Rule::enum(InfractionStatus::class)], 'reason' => ['required', 'string'],
                'expected_revision' => ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX]];
        }
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = ['type' => [$required, Rule::enum(InfractionType::class)],
            'description' => [$required, 'required', 'string'], 'observations' => ['sometimes', 'nullable', 'string']];
        if ($this->isMethod('POST')) {
            $rules['student_id'] = ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'exists:students,id'];
        } else {
            $rules['expected_revision'] = ['required', 'integer', 'min:0', 'max:'.PHP_INT_MAX];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'Este campo no está permitido en esta operación.');
            }
            if ($this->isMethod('PATCH') && ! $this->isStatusChange()
                && array_intersect(array_keys($this->all()), ['type', 'description', 'observations']) === []) {
                $validator->errors()->add('description', 'Indica al menos un campo para modificar.');
            }
        });
    }

    public function messages(): array
    {
        return ['type.*' => 'Selecciona copia, trampa o suplantación.',
            'description.*' => 'La descripción es obligatoria y debe ser texto.',
            'observations.*' => 'Las observaciones deben ser texto o nulas.',
            'student_id.*' => 'Selecciona un estudiante existente y válido.',
            'status.*' => 'Selecciona un estado válido: REPORTED, CONFIRMED o RESOLVED.',
            'reason.*' => 'Indica el motivo del cambio de estado.',
            'expected_revision.*' => 'Envía la versión vigente de la infracción.'];
    }
}
