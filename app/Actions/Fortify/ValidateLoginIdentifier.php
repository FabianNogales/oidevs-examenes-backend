<?php

namespace App\Actions\Fortify;

use App\Services\Auth\LoginUserResolver;
use App\Services\Auth\PasswordPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ValidateLoginIdentifier
{
    public function __construct(private readonly LoginUserResolver $resolver) {}

    public function handle(Request $request, Closure $next): mixed
    {
        Validator::make($request->all(), [
            'identifier' => [
                'bail',
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateIdentifier($attribute, (string) $value, $fail);
                },
            ],
            'password' => PasswordPolicy::loginRules(),
        ])->validate();

        return $next($request);
    }

    private function validateIdentifier(string $attribute, string $identifier, Closure $fail): void
    {
        if ($this->resolver->isEmailIdentifier($identifier)) {
            if (! $this->resolver->isValidEmail($identifier)) {
                $fail('El identificador debe ser un correo electronico valido.');

                return;
            }

            if (! $this->resolver->hasInstitutionalEmailDomain($identifier)) {
                $fail('El correo electronico debe pertenecer a un dominio institucional permitido.');
            }

            return;
        }

        // Teacher creation accepts any non-empty institutional code up to 50
        // characters, including short numeric codes. Existence is never syntax.
        if (mb_strlen($identifier) <= 50) {
            return;
        }

        if (! ctype_digit($identifier)) {
            $fail('El código institucional debe tener como máximo 50 caracteres.');
        }
    }
}
