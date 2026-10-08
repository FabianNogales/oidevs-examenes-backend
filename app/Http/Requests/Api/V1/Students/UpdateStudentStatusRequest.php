<?php

namespace App\Http\Requests\Api\V1\Students;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtoupper(trim($this->input('status')))]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([UserStatus::ACTIVE->value, UserStatus::INACTIVE->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado es obligatorio.',
            'status.string' => 'El estado debe ser un texto.',
            'status.in' => 'El estado debe ser ACTIVE o INACTIVE.',
        ];
    }
}
