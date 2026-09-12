<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginUserResolver
{
    /**
     * Resuelve el identificador de HU02 hacia un User autenticable.
     *
     * Con @ se interpreta como correo institucional; sin @ se interpreta como
     * codigo SIS asociado a un estudiante.
     */
    public function resolve(string $identifier, string $password): ?User
    {
        $user = $this->findUser($identifier);

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        if ($user->status !== UserStatus::ACTIVE->value) {
            throw ValidationException::withMessages([
                'identifier' => ['La cuenta se encuentra inactiva.'],
            ]);
        }

        return $user;
    }

    public function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($this->isEmailIdentifier($identifier)) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($identifier)])
                ->first();
        }

        return Student::query()
            ->with('user')
            ->where('sis_code', $identifier)
            ->first()
            ?->user;
    }

    public function isEmailIdentifier(string $identifier): bool
    {
        return str_contains($identifier, '@');
    }

    public function isValidEmail(string $identifier): bool
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function hasInstitutionalEmailDomain(string $email): bool
    {
        $allowedDomains = $this->institutionalEmailDomains();

        if ($allowedDomains === []) {
            return false;
        }

        $domain = strtolower((string) strrchr($email, '@'));
        $domain = ltrim($domain, '@');

        return in_array($domain, $allowedDomains, true);
    }

    /**
     * @return array<int, string>
     */
    public function institutionalEmailDomains(): array
    {
        return array_values(array_filter(array_map(
            fn (string $domain): string => strtolower(trim($domain)),
            config('eida.institutional_email_domains', []),
        )));
    }
}
