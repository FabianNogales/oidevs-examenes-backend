<?php

namespace App\Services\Auth;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 64;

    public static function loginRules(): array
    {
        // Legacy initial credentials must remain usable before their mandatory change.
        return ['required', 'string', 'min:1', 'max:'.self::MAX_LENGTH];
    }

    public static function newPasswordRules(): array
    {
        return ['required', 'string', Password::min(self::MIN_LENGTH), 'max:'.self::MAX_LENGTH, 'confirmed',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                if (str_contains($value, "\0")) {
                    $fail('La contraseña no puede contener bytes nulos.');
                }
                if (Hash::getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
                    $fail('La contraseña supera el límite de 72 bytes UTF-8 del algoritmo actual. Usa una frase de hasta 64 caracteres que no exceda ese límite.');
                }
            }];
    }
}
