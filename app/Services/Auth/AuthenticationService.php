<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

class AuthenticationService
{
    public function revokeSessionToken(Request $request): void
    {
        // Sanctum elimina el token de acceso actual del usuario
        $request->user()->currentAccessToken()->delete();
    }
}