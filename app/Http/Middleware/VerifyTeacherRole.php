<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTeacherRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole(RoleName::DOCENTE)) {
            return response()->json(['message' => 'Forbidden - Insufficient permissions'], 403);
        }

        return $next($request);
    }
}
