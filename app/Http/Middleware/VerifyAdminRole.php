<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VerifyAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $isAdmin = DB::table('role_user')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $user->id)
            ->whereIn('roles.name', ['Admin', 'Administrador', 'ADMINISTRADOR'])
            ->where('role_user.status', 'ACTIVE')
            ->where('roles.status', 'ACTIVE')
            ->exists();

        if (!$isAdmin) {
            DB::table('audit_logs')->insert([
                'user_id' => $user->id,
                'action' => 'ADMIN_ACCESS_DENIED',
                'entity_type' => 'ADMIN_PANEL',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return response()->json([
                'code' => 'FORBIDDEN',
                'message' => 'Forbidden - Insufficient permissions'
            ], 403);
        }

        DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'action' => 'ADMIN_ACCESS_GRANTED',
            'entity_type' => 'ADMIN_PANEL',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return $next($request);
    }
}