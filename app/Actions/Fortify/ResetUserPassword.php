<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\MobileDeviceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'must_change_password' => false,
            ])->save();
            app(MobileDeviceService::class)->revokeForPassword($user, 'PASSWORD_RESET');
        }, 3);
    }
}
