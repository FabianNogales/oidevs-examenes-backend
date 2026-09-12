<?php

namespace App\Http\Requests\Api\V1\Teachers;

use App\Http\Requests\Api\V1\Teachers\Concerns\ValidatesTeacherInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreTeacherRequest extends FormRequest
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
        return [
            'institutional_code' => ['required', 'string', 'max:50', 'unique:teachers,institutional_code'],
            'identity_number' => ['required', 'string', 'max:50', 'unique:teachers,identity_number'],
            'first_names' => ['required', 'string', 'max:100'],
            'last_names' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', $this->institutionalEmailDomainRule()],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [$this->afterValidator()];
    }
}
