<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\HandleCors;
use Symfony\Component\HttpFoundation\Response;

class HandleExplicitCors extends HandleCors
{
    public function handle($request, Closure $next): Response
    {
        $matches = $this->hasMatchingPath($request);
        $response = parent::handle($request, $next);
        if (! $matches) {
            return $response;
        }

        // Single-origin CorsService responses also advertise that origin to
        // other callers. Keep headers exclusive to the actual allowed origin.
        // Both allowed and denied responses vary so caches cannot mix them.
        $this->cors->varyHeader($response, 'Origin');
        if ($this->cors->isCorsRequest($request) && $this->cors->isOriginAllowed($request)) {
            return $response;
        }

        foreach (array_keys($response->headers->all()) as $header) {
            if (str_starts_with(strtolower($header), 'access-control-')) {
                $response->headers->remove($header);
            }
        }

        return $response;
    }
}
