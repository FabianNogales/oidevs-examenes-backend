<?php

namespace App\Services\Admin;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdministratorOperation
{
    public function run(Request $request, callable $operation): mixed
    {
        return DB::transaction(function () use ($request, $operation) {
            // Match HU22 lock order and recheck the actor before committing access changes.
            Role::where('name', RoleName::ADMINISTRADOR->value)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isActive() && ! $actor->must_change_password
                && $actor->hasRole(RoleName::ADMINISTRADOR), 403);
            if ($request->hasSession() && $actor->active_session_id) {
                abort_unless($actor->active_session_id === $request->session()->getId(), 401);
            }

            return $operation($actor);
        }, 3);
    }
}
