<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

        if (in_array($request->method(), $mutatingMethods)) {
            return response()->json(['message' => 'Forbidden - Action requires higher authorization level'], 403);
        }

        return $next($request);
    }
}