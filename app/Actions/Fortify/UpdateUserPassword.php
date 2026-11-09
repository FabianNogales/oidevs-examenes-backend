<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\MobileDeviceService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $originalHash = $user->getAuthPassword();
        DB::transaction(function () use ($user, $input, $originalHash) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($originalHash, $locked->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña cambió. Inicia sesión nuevamente.']);
            }
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'must_change_password' => false,
            ])->save();
            app(MobileDeviceService::class)->revokeForPassword($user, 'PASSWORD_CHANGED');
        }, 3);

        $request = request();
        $guardName = config('fortify.guard', 'web');
        $guard = Auth::guard($guardName);

        if ($request->hasSession()
            && $guard instanceof SessionGuard
            && $guard->user()?->is($user)
            && $guard->getSession() === $request->session()
            && $user->active_session_id === $request->session()->getId()) {
            // Match Sanctum's hash format only for the current authorized session.
            $passwordHash = $user->getAuthPassword();
            $request->session()->put(
                'password_hash_'.$guardName,
                method_exists($guard, 'hashPasswordForCookie')
                    ? $guard->hashPasswordForCookie($passwordHash)
                    : $passwordHash,
            );
        }
    }
}
