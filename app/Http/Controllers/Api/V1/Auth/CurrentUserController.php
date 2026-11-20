<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\CurrentUserResource;
use App\Services\Admin\RolePermissionCatalog;
use App\Services\Auth\MobileTokenPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class CurrentUserController extends Controller
{
    /**
     * HU02: devuelve la identidad y el estado de autenticacion del usuario actual.
     *
     * Este endpoint es la fuente de verdad del frontend para restaurar la sesion
     * y conocer nombre visible, roles y must_change_password.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('activeRoles', 'teacher', 'student');

        $data = CurrentUserResource::make($user)->resolve();
        $token = $user->currentAccessToken();
        // Keep the APK identity resource/login contract unchanged. Only web receives capabilities.
        if (! ($token instanceof PersonalAccessToken && MobileTokenPermissions::isMobile($token))) {
            $data['permissions'] = app(RolePermissionCatalog::class)->effective($user);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
