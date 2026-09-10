<?php

namespace App\Actions\Fortify;

use App\Services\Auth\LoginAuditService;
use Closure;
use Illuminate\Http\Request;

class RecordLastLogin
{
    public function __construct(private readonly LoginAuditService $loginAuditService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($user = $request->user()) {
            $user->forceFill([
                'last_login_at' => now(),
                'active_session_id' => $request->session()->getId(),
            ])->save();

            $this->loginAuditService->recordSuccessfulLogin($user, $request);
        }

        return $next($request);
    }
}
