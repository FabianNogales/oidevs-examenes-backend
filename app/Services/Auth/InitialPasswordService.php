<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class InitialPasswordService
{
    /**
     * Servicio reutilizable por HU04 y HU05 para crear credenciales iniciales.
     *
     * Usa el CI como password inicial, guarda solamente el hash y marca al
     * usuario para cambio obligatorio en su primer acceso.
     */
    public function initialize(User $user, string $identityNumber): User
    {
        $user->forceFill([
            'password' => Hash::make($identityNumber),
            'must_change_password' => true,
        ])->save();

        return $user;
    }
}
