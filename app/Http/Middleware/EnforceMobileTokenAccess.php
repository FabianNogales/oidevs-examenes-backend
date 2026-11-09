<?php

namespace App\Http\Middleware;

use App\Models\MobileDevice;
use App\Services\Auth\MobileTokenPermissions;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnforceMobileTokenAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $presented = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        if (! $presented || ! MobileTokenPermissions::isMobile($presented)) {
            return $next($request);
        }
        // Sanctum alone validates the token hash and expiration. A SPA cookie must
        // never hide a mobile token and turn it into unrestricted web authority.
        $actor = $request->user('sanctum');
        $token = $actor?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->id !== $presented->id || $token->expires_at === null) {
            return response()->json(['message' => 'Utiliza un token móvil vigente sin sesión web.', 'code' => 'MOBILE_TOKEN_INVALID'], 401);
        }
        if (! $actor->isActive()) {
            return response()->json(['message' => 'La cuenta se encuentra inactiva.', 'code' => 'ACCOUNT_INACTIVE'], 401);
        }
        $device = MobileDevice::whereKey($token->mobile_device_id)->where('user_id', $actor->id)->whereNull('revoked_at')->first();
        if (! $device) {
            return response()->json(['message' => 'La instalación móvil fue revocada.', 'code' => 'MOBILE_DEVICE_REVOKED'], 401);
        }
        $ability = app(MobileTokenPermissions::class)->requiredAbility($request);
        // Deny unlisted APIs even if a mobile token is accidentally granted "*".
        if ($ability === null || ! $token->can($ability)) {
            return response()->json(['message' => 'El token móvil no permite esta operación.', 'code' => 'MOBILE_ABILITY_REQUIRED'], 403);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
