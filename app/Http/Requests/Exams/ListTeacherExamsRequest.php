<?php

namespace App\Http\Requests\Exams;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListTeacherExamsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    public function rules(): array
    {
        return ['view' => ['sometimes', 'string', Rule::in(['upcoming', 'past', 'cancelled', 'all'])],
            'search' => ['nullable', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'in:10']];
    }

    public function messages(): array
    {
        return ['view.*' => 'La vista debe ser upcoming, past, cancelled o all.',
            'search.*' => 'La búsqueda debe ser un texto de hasta 255 caracteres.',
            'page.*' => 'La página debe ser un entero positivo dentro del rango permitido.',
            'per_page.*' => 'La cantidad de exámenes por página debe ser 10.'];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.',
            'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()->toArray()], 422));
    }
}
