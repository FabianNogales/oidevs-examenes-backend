<?php

namespace App\Http\Requests\Api\V1\Rooms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['code', 'name', 'location', 'description', 'floor'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field), ' ');
                $values[$field] = in_array($field, ['code', 'name']) ? $value : ($value === '' ? null : $value);
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $id = $this->route('room')?->id;
        $singleLine = function ($attribute, $value, $fail) {
            if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                $fail('El campo debe ser de una sola línea y no contener caracteres de control.');
            }
        };
        $description = function ($attribute, $value, $fail) {
            if (is_string($value) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
                $fail('La descripción contiene caracteres de control no permitidos.');
            }
        };

        return [
            'code' => ['required', 'string', 'max:255', $singleLine, Rule::unique('rooms', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255', $singleLine, Rule::unique('rooms', 'name')->ignore($id)],
            'location' => ['nullable', 'string', 'max:255', $singleLine],
            'description' => ['nullable', 'string', 'max:1000', $description],
            'floor' => ['nullable', 'string', 'max:50', $singleLine],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'status' => ['prohibited'],
            'availability' => ['prohibited'],
            'current_exam' => ['prohibited'],
            'id' => ['prohibited'],
            'exams' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'El código ya está registrado.',
            'name.unique' => 'El nombre ya está registrado.',
        ];
    }
}
