<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\User;
use App\Services\Auth\LoginUserResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreStaffAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() && $this->user()->hasRole('ADMINISTRADOR');
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['first_names', 'last_names', 'identity_number', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = $field === 'email' ? strtolower(trim($this->input($field))) : trim($this->input($field));
            }
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'first_names' => ['required', 'string', 'max:255'],
            'last_names' => ['required', 'string', 'max:255'],
            // Same string/numeric CI and institutional email rules as manual student registration.
            'identity_number' => ['bail', 'required', 'string', 'max:255', 'regex:/\A[0-9]+\z/',
                'unique:users,staff_identity_number', 'unique:students,identity_number', 'unique:teachers,identity_number'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(LoginUserResolver::class)->hasInstitutionalEmailDomain($value)) {
                        $fail('El correo debe pertenecer a un dominio institucional configurado.');
                    }
                    if (User::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail('El correo institucional ya está registrado.');
                    }
                }],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'password' => ['prohibited'], 'roles' => ['prohibited'], 'status' => ['prohibited'],
            'sis_code' => ['prohibited'], 'teacher_id' => ['prohibited'], 'student_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['identity_number.regex' => 'El CI debe contener únicamente números.',
            'identity_number.unique' => 'El CI ya está registrado.', 'email.email' => 'El correo institucional no es válido.'];
    }
}
