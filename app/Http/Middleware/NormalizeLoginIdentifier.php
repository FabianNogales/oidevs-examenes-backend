<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeLoginIdentifier
{
    /**
     * Normalize the Fortify login identifier while keeping the legacy email field working.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('login') && $request->isMethod('post')) {
            $identifier = $request->input('identifier', $request->input('email'));

            if (is_string($identifier)) {
                $request->merge([
                    'identifier' => trim($identifier),
                ]);
            }
        }

        return $next($request);
    }
}
