<?php

namespace App\Http\Requests\Api\V1\Students;

use App\Models\User;
use App\Services\Auth\LoginUserResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La ruta hereda la autorización del grupo administrativo.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['sis_code', 'identity_number', 'first_names', 'last_names', 'email'] as $field) {
            $value = $this->input($field);

            // Mantener tipos inválidos para que la regla string los rechace.
            if (is_string($value)) {
                $normalized[$field] = $field === 'email' ? strtolower(trim($value)) : trim($value);
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        // SIS y CI se exigen como strings para conservar sus ceros iniciales.
        return [
            'sis_code' => ['bail', 'required', 'string', 'max:255', 'unique:students,sis_code'],
            'identity_number' => ['bail', 'required', 'string', 'max:255', 'regex:/\A[0-9]+\z/', 'unique:students,identity_number'],
            'first_names' => ['required', 'string', 'max:255'],
            'last_names' => ['required', 'string', 'max:255'],
            'email' => [
                'bail', 'required', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $resolver = app(LoginUserResolver::class);

                    if (! $resolver->hasInstitutionalEmailDomain($value)) {
                        $fail('El correo debe pertenecer a un dominio institucional configurado.');
                    }

                    if (User::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail('El correo institucional ya está registrado.');
                    }
                },
            ],
            'career_id' => ['bail', 'required', 'integer', 'exists:careers,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser un texto.',
            'max' => 'El campo :attribute no debe superar los :max caracteres.',
            'sis_code.unique' => 'El código SIS ya está registrado.',
            'identity_number.regex' => 'El CI debe contener únicamente números.',
            'identity_number.unique' => 'El CI ya está registrado.',
            'email.email' => 'El correo institucional no tiene un formato válido.',
            'career_id.integer' => 'El identificador de carrera debe ser un número entero.',
            'career_id.exists' => 'La carrera indicada no existe.',
        ];
    }

    public function attributes(): array
    {
        return [
            'sis_code' => 'código SIS',
            'identity_number' => 'CI',
            'first_names' => 'nombres',
            'last_names' => 'apellidos',
            'email' => 'correo institucional',
            'career_id' => 'carrera',
        ];
    }
}
