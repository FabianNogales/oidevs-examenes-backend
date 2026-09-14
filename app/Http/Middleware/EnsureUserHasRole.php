<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Services\Audit\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function __construct(
        private AuditLogService $auditLogService
    ) {
    }

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole($role)) {

            if ($role === RoleName::ADMINISTRADOR->value) {
                $this->auditLogService->log(
                    $request,
                    $user?->id,
                    'ADMIN_ACCESS_DENIED'
                );
            }

            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para acceder a este recurso.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        if ($role === RoleName::ADMINISTRADOR->value) {
            $this->auditLogService->log(
                $request,
                $user->id,
                'ADMIN_ACCESS_GRANTED'
            );
        }

        return $next($request);
    }
}