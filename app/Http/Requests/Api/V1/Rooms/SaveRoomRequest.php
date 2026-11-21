<?php

namespace App\Http\Requests\Api\V1\Rooms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\UniqueCatalogName;

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
        if ($this->input('floor') === 'PB' || $this->boolean('is_ground_floor')) {
            $this->merge(['floor' => '0']);
        }
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
            'code' => [$id ? 'sometimes' : 'nullable', $id ? 'required' : 'nullable', 'string', 'max:255', $singleLine, Rule::unique('rooms', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255', $singleLine, new UniqueCatalogName('rooms', $id)],
            'location' => ['nullable', 'string', 'max:255', $singleLine],
            'description' => ['nullable', 'string', 'max:1000', $description],
            'floor' => ['nullable', 'integer', 'between:-5,200'],
            'is_ground_floor' => ['sometimes', 'boolean'],
            'building_id' => ['sometimes', 'nullable', 'integer', Rule::exists('buildings', 'id')->where('is_active', true)],
            'capacity' => ['nullable', 'integer', 'min:'.config('catalog_limits.room_min_capacity'), 'max:2147483647'],
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
