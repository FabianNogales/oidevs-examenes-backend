<?php

namespace App\Http\Requests\Api\V1\Subjects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['code', 'name'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $values[$field] = $field === 'code' ? strtoupper($value) : $value;
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'code' => ['bail', 'required', 'string', 'max:50', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/'],
            'name' => ['bail', 'required', 'string', 'min:2', 'max:255', 'regex:/\p{L}/u', 'not_regex:/\p{Cc}/u'],
            'career_ids' => ['required', 'array', 'min:1'],
            'career_ids.*' => ['bail', 'required', function ($attribute, $value, $fail) {
                if (! is_int($value) || $value < 1) {
                    $fail('Cada ID de carrera debe ser un entero positivo, no un texto.');
                }
            }, 'distinct', 'exists:careers,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['code', 'name', 'career_ids']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en el registro o edición de materias.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'code.*' => 'El código es obligatorio, de hasta 50 caracteres; usa letras ASCII, números, punto, guion o guion bajo y empieza con letra o número.',
            'name.*' => 'El nombre debe tener entre 2 y 255 caracteres, contener una letra y no incluir caracteres de control.',
            'career_ids.*' => 'Selecciona al menos una carrera mediante una lista de IDs.',
            'career_ids.*.required' => 'El ID de carrera es obligatorio.',
            'career_ids.*.distinct' => 'No repitas IDs de carreras.',
            'career_ids.*.exists' => 'La carrera seleccionada no existe.',
        ];
    }
}
