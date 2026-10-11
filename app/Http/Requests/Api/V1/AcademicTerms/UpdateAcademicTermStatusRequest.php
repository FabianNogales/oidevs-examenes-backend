<?php

namespace App\Http\Requests\Api\V1\AcademicTerms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateAcademicTermStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in(['ACTIVE', 'INACTIVE'])]];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['status']) as $field) {
                $validator->errors()->add($field, 'Solo puedes modificar el estado en esta operación.');
            }
        });
    }

    public function messages(): array
    {
        return ['status.*' => 'El estado es obligatorio y debe ser ACTIVE o INACTIVE.'];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.',
            'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()->toArray()], 422));
    }
}
