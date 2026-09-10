<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Http\Request;

class RecordLastLogin
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->user()?->forceFill([
            'last_login_at' => now(),
        ])->save();

        return $next($request);
    }
}
