<?php

namespace App\Http\Requests\Api\V1\Teachers;

use App\Http\Requests\Api\V1\Teachers\Concerns\ValidatesTeacherInput;
use App\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
{
    use ValidatesTeacherInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTeacherInput();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $teacher = $this->teacher();

        return [
            'institutional_code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('teachers', 'institutional_code')->ignore($teacher?->id),
            ],
            'identity_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('teachers', 'identity_number')->ignore($teacher?->id),
            ],
            'first_names' => ['sometimes', 'required', 'string', 'max:100'],
            'last_names' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($teacher?->user_id),
                $this->institutionalEmailDomainRule(),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [$this->afterValidator()];
    }

    private function teacher(): ?Teacher
    {
        $teacher = $this->route('teacher');

        if ($teacher instanceof Teacher) {
            return $teacher;
        }

        if ($teacher === null) {
            return null;
        }

        return Teacher::query()->find($teacher);
    }
}
