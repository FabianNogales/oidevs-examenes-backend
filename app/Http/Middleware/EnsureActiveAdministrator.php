<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authorization for administrative read catalogs; consulting logs does not log itself. */
class EnsureActiveAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isActive() || ! $user->hasRole(RoleName::ADMINISTRADOR)) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'No tiene permisos para consultar este recurso administrativo.'], 403);
        }

        return $next($request);
    }
}
