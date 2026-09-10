<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class InitialPasswordService
{
    public function initialize(User $user, string $identityNumber): User
    {
        $user->forceFill([
            'password' => Hash::make($identityNumber),
            'must_change_password' => true,
        ])->save();

        return $user;
    }
}
