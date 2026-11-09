<?php

namespace App\Http\Requests\Auth;

use App\Actions\Fortify\ValidateLoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class MobileLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('identifier'))) {
            $this->merge(['identifier' => trim($this->input('identifier'))]);
        }
        if (is_string($this->input('installation_id'))) {
            $this->merge(['installation_id' => strtolower($this->input('installation_id'))]);
        }
        if (is_string($this->input('device_name'))) {
            $this->merge(['device_name' => trim($this->input('device_name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:20'],
            'installation_id' => ['required', 'uuid'],
            'device_name' => ['required', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function passedValidation(): void
    {
        // Reuse HU02's institutional identifier policy without Fortify's session pipeline.
        app(ValidateLoginIdentifier::class)->handle($this, fn () => null);
    }
}
