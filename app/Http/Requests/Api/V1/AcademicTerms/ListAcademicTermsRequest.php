<?php

namespace App\Http\Requests\Api\V1\AcademicTerms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListAcademicTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])]];
    }

    public function messages(): array
    {
        return ['page.*' => 'La página debe ser un entero positivo.',
            'per_page.*' => 'La cantidad por página debe estar entre 1 y 100.',
            'search.*' => 'La búsqueda debe ser un texto de hasta 150 caracteres.',
            'status.*' => 'El estado debe ser ACTIVE o INACTIVE.'];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.',
            'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()->toArray()], 422));
    }
}
