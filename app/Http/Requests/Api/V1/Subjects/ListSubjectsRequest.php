<?php

namespace App\Http\Requests\Api\V1\Subjects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSubjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'career_id' => ['sometimes', 'integer', 'min:1', 'exists:careers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.*' => 'La página debe ser un entero positivo.',
            'per_page.*' => 'La cantidad por página debe ser un entero entre 1 y 100.',
            'search.*' => 'La búsqueda debe ser un texto de hasta 150 caracteres.',
            'status.*' => 'El estado debe ser ACTIVE o INACTIVE.',
            'career_id.*' => 'Selecciona una carrera existente mediante su ID entero positivo.',
        ];
    }
}
