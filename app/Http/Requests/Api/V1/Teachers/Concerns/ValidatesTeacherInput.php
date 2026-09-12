<?php

namespace App\Http\Requests\Api\V1\Teachers\Concerns;

use Closure;
use Illuminate\Validation\Validator;

trait ValidatesTeacherInput
{
    protected function prepareTeacherInput(): void
    {
        $this->merge(array_filter([
            'institutional_code' => $this->trimmedInput('institutional_code'),
            'identity_number' => $this->trimmedInput('identity_number'),
            'first_names' => $this->trimmedInput('first_names'),
            'last_names' => $this->trimmedInput('last_names'),
            'email' => $this->normalizedEmail(),
        ], fn (mixed $value): bool => $value !== null));
    }

    protected function institutionalEmailDomainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $domains = $this->institutionalEmailDomains();
            $email = strtolower((string) $value);
            $domain = ltrim((string) strrchr($email, '@'), '@');

            if ($domains === [] || ! in_array($domain, $domains, true)) {
                $fail('El correo electronico debe pertenecer a un dominio institucional permitido.');
            }
        };
    }

    /**
     * @return array<int, string>
     */
    protected function institutionalEmailDomains(): array
    {
        return array_values(array_filter(array_map(
            fn (string $domain): string => strtolower(trim($domain)),
            config('eida.institutional_email_domains', []),
        )));
    }

    protected function afterValidator(): Closure
    {
        return function (Validator $validator): void {
            if ($this->exists('email') && $this->institutionalEmailDomains() === []) {
                $validator->errors()->add(
                    'email',
                    'No existen dominios institucionales configurados para validar docentes.',
                );
            }
        };
    }

    private function trimmedInput(string $key): ?string
    {
        if (! $this->exists($key)) {
            return null;
        }

        return trim((string) $this->input($key));
    }

    private function normalizedEmail(): ?string
    {
        if (! $this->exists('email')) {
            return null;
        }

        return strtolower(trim((string) $this->input('email')));
    }
}
