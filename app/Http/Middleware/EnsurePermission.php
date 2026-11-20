<?php

namespace App\Http\Middleware;

use App\Services\Admin\PermissionAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private readonly PermissionAuthorization $access) {}

    // Several keys mean an explicit OR for a shared form dependency, never implicit grants.
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($permissions as $permission) {
            if ($this->access->allows($request->user(), $permission)) {
                return $next($request);
            }
        }
        abort(403, 'No tienes permiso vigente para esta operación.');
    }
}
