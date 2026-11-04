<?php

namespace App\Http\Middleware;

use App\Services\Infractions\InfractionAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureInfractionExamOwner
{
    public function handle(Request $request, Closure $next)
    {
        $id = filter_var($request->route('exam_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404, 'Examen no encontrado.');
        app(InfractionAccess::class)->exam($id, $request->user());

        return $next($request);
    }
}
