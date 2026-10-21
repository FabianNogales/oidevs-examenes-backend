<?php

namespace App\Http\Requests\Exams;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class UpdateExamRequest extends StoreExamRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        if (is_string($this->input('rules'))) {
            $rules = trim($this->input('rules'));
            $this->merge(['rules' => $rules === '' ? null : $rules]);
        }
    }

    public function rules(): array
    {
        $integer = function ($attribute, $value, $fail) {
            if (! is_int($value)) {
                $fail('Este campo debe ser un número entero JSON.');
            }
        };

        return [
            'name' => ['bail', 'required', 'string', 'max:255'],
            'exam_date' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:'.now('America/La_Paz')->toDateString()],
            'start_time' => ['bail', 'required', 'string', 'date_format:H:i:s'],
            'duration_minutes' => ['bail', 'required', $integer, 'integer', 'min:1', 'max:2147483647'],
            'room_id' => ['bail', 'required', $integer, 'integer', 'min:1', 'exists:rooms,id'],
            'evaluation_type' => ['bail', 'required', 'string', 'in:partial,final,makeup'],
            'rules' => ['present', 'nullable', 'string'],
            'expected_updated_at' => ['bail', 'required', 'string',
                'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Este campo no se puede modificar.');
            }
            if (! $validator->errors()->has('exam_date') && ! $validator->errors()->has('start_time')) {
                $start = CarbonImmutable::parse($this->input('exam_date').' '.$this->input('start_time'), 'America/La_Paz');
                if ($start->lessThanOrEqualTo(CarbonImmutable::now('America/La_Paz'))) {
                    $validator->errors()->add('start_time', 'La fecha y hora del examen deben estar en el futuro.');
                }
            }
        });
    }

    public function messages(): array
    {
        return ['name.*' => 'El nombre es obligatorio y debe tener hasta 255 caracteres.',
            'exam_date.*' => 'Selecciona una fecha real YYYY-MM-DD que no sea anterior a hoy.',
            'start_time.*' => 'La hora debe usar el formato HH:mm:ss.',
            'duration_minutes.*' => 'La duración debe ser un entero entre 1 y 2147483647.',
            'room_id.*' => 'Selecciona un aula existente mediante su ID entero positivo.',
            'evaluation_type.*' => 'El tipo debe ser partial, final o makeup.',
            'rules.*' => 'Envía las normas como texto o null.',
            'expected_updated_at.*' => 'Envía la versión del examen como una fecha ISO 8601 con zona horaria.'];
    }
}
