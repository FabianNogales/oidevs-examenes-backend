<?php

namespace App\Actions\Fortify;

use App\Services\Auth\LoginUserResolver;
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
                'required',
                'string',
                'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateIdentifier($attribute, (string) $value, $fail);
                },
            ],
            'password' => ['required', 'string', 'min:8', 'max:20'],
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

        if (! ctype_digit($identifier)) {
            $fail('El codigo SIS debe contener solo numeros.');

            return;
        }

        if (strlen($identifier) < 9) {
            $fail('El codigo SIS debe tener al menos 9 digitos.');
        }
    }
}
