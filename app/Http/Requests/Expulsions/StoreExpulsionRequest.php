<?php

namespace App\Http\Requests\Expulsions;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpulsionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return ['student_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'exists:students,id'],
            'reason' => ['required', 'string'],
            'infraction_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'discipline_case_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX]];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['student_id', 'reason', 'infraction_id', 'discipline_case_id']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en el registro de expulsiones.');
            }
        });
    }

    public function messages(): array
    {
        return ['student_id.*' => 'Selecciona un estudiante existente y válido.',
            'reason.*' => 'El motivo de la expulsión es obligatorio y debe ser texto.',
            'infraction_id.*' => 'Selecciona una infracción válida o deja la asociación vacía.'];
    }
}
