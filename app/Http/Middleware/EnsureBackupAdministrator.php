<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A module boundary for future explicit permissions; no delegations enabled today. */
class EnsureBackupAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->activeRoles()->where('roles.name', RoleName::ADMINISTRADOR->value)
            ->where('roles.is_custom', false)->exists(), 403);

        return $next($request);
    }
}
