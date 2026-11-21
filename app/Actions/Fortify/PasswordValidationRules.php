<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use App\Services\Auth\PasswordPolicy;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return PasswordPolicy::newPasswordRules();
    }
}
