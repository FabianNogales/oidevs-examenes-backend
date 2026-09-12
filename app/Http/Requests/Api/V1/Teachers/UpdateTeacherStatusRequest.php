<?php

namespace App\Http\Requests\Api\V1\Teachers;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('status')) {
            $this->merge([
                'status' => strtoupper(trim((string) $this->input('status'))),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    UserStatus::ACTIVE->value,
                    UserStatus::INACTIVE->value,
                ]),
            ],
        ];
    }
}
