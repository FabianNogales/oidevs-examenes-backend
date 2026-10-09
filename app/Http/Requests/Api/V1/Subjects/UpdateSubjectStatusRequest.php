<?php

namespace App\Http\Requests\Api\V1\Subjects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSubjectStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in(['ACTIVE', 'INACTIVE'])]];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['status']) as $field) {
                $validator->errors()->add($field, 'Solo puedes modificar el estado en esta operación.');
            }
        });
    }

    public function messages(): array
    {
        return ['status.*' => 'El estado es obligatorio y debe ser ACTIVE o INACTIVE.'];
    }
}
