<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClearActiveSessionOnLogout
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = null;
        $sessionId = null;

        if ($request->is('logout') && $request->isMethod('post') && $request->user()) {
            $userId = $request->user()->getAuthIdentifier();
            $sessionId = $request->session()->getId();
        }

        $response = $next($request);

        if ($userId && $sessionId) {
            User::query()
                ->whereKey($userId)
                ->where('active_session_id', $sessionId)
                ->update(['active_session_id' => null]);
        }

        return $response;
    }
}
