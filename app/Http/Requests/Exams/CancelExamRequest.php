<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expected_updated_at' => ['bail', 'required', 'string',
            'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', 'date']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['expected_updated_at']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido al cancelar un examen.');
            }
        });
    }

    public function messages(): array
    {
        return ['expected_updated_at.*' => 'Envía la versión del examen como una fecha ISO 8601 con zona horaria.'];
    }
}
