<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para acceder a este recurso.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}