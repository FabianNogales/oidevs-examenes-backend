<?php

namespace App\Http\Middleware;

use App\Services\Auth\PasswordResetDeliveryPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordResetDelivery
{
    public function __construct(private readonly PasswordResetDeliveryPolicy $delivery) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && $request->isMethod('POST') && $request->routeIs('password.email')
            && ! $this->delivery->canDeliverWithoutLogging()) {
            // Refuse before the broker creates a reset token or a notification.
            return response()->json([
                'code' => 'PASSWORD_RECOVERY_UNAVAILABLE',
                'message' => 'La recuperación por correo no está disponible. Comunícate con administración.',
            ], 503)->header('Cache-Control', 'private, no-store');
        }

        return $next($request);
    }
}
