<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['view' => ['sometimes', Rule::in(['all', 'registered', 'pending'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Este parámetro no está disponible en la supervisión de asistencia.');
            }
        });
    }

    public function messages(): array
    {
        return ['view.*' => 'Selecciona all, registered o pending.',
            'search.*' => 'La búsqueda debe ser texto de hasta 255 caracteres.',
            'page.*' => 'La página debe ser un entero positivo válido.',
            'per_page.*' => 'La cantidad por página debe estar entre 1 y 100.'];
    }
}
