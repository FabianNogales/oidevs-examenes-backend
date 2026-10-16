<?php

namespace App\Http\Middleware;

use App\Services\CourseOfferings\CourseOfferingAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCourseOfferingAccess
{
    public function __construct(private readonly CourseOfferingAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->route('courseOffering');
        if (! preg_match('/\A[1-9][0-9]*\z/', $id) || strlen($id) > strlen((string) PHP_INT_MAX)
            || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
            abort(404);
        }

        return $this->access->run((int) $id, $request->user()->id, ! $request->isMethodSafe(), fn () => $next($request));
    }
}
