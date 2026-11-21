<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\MobileDeviceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

        $attributes = DB::transaction(function () use ($user, $input) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'password' => Hash::make($input['password']),
                'must_change_password' => false,
                'active_session_id' => Str::random(40),
                'remember_token' => Str::random(60),
            ])->save();
            app(MobileDeviceService::class)->revokeForPassword($locked, 'PASSWORD_RESET');

            return $locked->getAttributes();
        }, 3);

        // Fortify completes the reset with this instance. Keep its later save
        // from restoring stale attributes, and only update it after commit.
        $user->setRawAttributes($attributes, true);
    }
}
