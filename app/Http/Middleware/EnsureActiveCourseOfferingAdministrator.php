<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCourseOfferingAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isActive() || ! $user->hasRole(RoleName::ADMINISTRADOR)) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'No tiene permisos para administrar ofertas académicas.'], 403);
        }

        return $next($request);
    }
}
