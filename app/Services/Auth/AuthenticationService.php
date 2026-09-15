<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    public function revokeSessionToken(Request $request): void
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        if ($request->hasSession()) {
            // Una sesion reemplazada no debe limpiar la sesion nueva.
            $user->newQuery()
                ->whereKey($user->getAuthIdentifier())
                ->where('active_session_id', $request->session()->getId())
                ->update(['active_session_id' => null]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
