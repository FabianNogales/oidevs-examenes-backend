<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();

        // HU02 permite una sola sesion activa: la cookie actual debe coincidir
        // con users.active_session_id; una sesion anterior recibe SESSION_REPLACED.
        if ($user->active_session_id && $user->active_session_id !== $sessionId) {
            // No se limpia active_session_id aqui porque puede pertenecer a una
            // sesion nueva que reemplazo a la cookie antigua.
            Auth::guard(config('fortify.guard', 'web'))->logoutCurrentDevice();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'success' => false,
                'message' => 'La sesion fue cerrada porque se inicio sesion en otro dispositivo.',
                'code' => 'SESSION_REPLACED',
            ], 401);
        }

        return $next($request);
    }
}