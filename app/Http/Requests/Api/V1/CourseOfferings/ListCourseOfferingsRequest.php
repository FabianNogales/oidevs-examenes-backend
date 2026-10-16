<?php

namespace App\Http\Requests\Api\V1\CourseOfferings;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListCourseOfferingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15'],
            'search' => ['nullable', 'string'],
            'teacher_id' => ['sometimes', 'integer', 'min:1', 'exists:teachers,id'],
            'academic_term_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_terms,id'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    public function messages(): array
    {
        return [
            'page.*' => 'La página debe ser un entero positivo.',
            'per_page.*' => 'La cantidad por página debe ser 15.',
            'search.*' => 'La búsqueda debe ser un texto.',
            'teacher_id.*' => 'Selecciona un docente existente mediante su ID entero positivo.',
            'academic_term_id.*' => 'Selecciona un periodo existente mediante su ID entero positivo.',
            'status.*' => 'El estado debe ser ACTIVE o INACTIVE.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.',
            'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()->toArray()], 422));
    }
}
