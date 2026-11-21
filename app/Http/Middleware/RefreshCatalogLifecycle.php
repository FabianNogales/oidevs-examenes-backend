<?php

namespace App\Http\Middleware;

use App\Services\CatalogLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshCatalogLifecycle
{
    public function handle(Request $request, Closure $next): Response
    {
        app(CatalogLifecycle::class)->refresh($request);

        return $next($request);
    }
}
