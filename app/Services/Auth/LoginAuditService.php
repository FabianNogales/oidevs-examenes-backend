<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class LoginAuditService
{
    public function recordSuccessfulLogin(User $user, Request $request): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'LOGIN',
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
