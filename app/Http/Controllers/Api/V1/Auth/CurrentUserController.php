<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\CurrentUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json([
            'success' => true,
            'data' => CurrentUserResource::make($user)->resolve(),
        ]);
    }
}
