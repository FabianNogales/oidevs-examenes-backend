<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\Students\HistoricalPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        // Flujo de primer acceso: las rutas protegidas pueden exigir cambio de
        // password, mientras login, /me, logout y /user/password siguen disponibles.
        if ($request->user()?->must_change_password) {
            return response()->json([
                'success' => false,
                'message' => 'Debe cambiar su contrasena antes de continuar.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 403);
        }

        if (! $request->routeIs('students.historical-photo.register')
            && $request->user() && app(HistoricalPhotoService::class)->requiredFor($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Debe registrar su fotografía histórica antes de continuar.',
                'code' => 'HISTORICAL_PHOTO_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
