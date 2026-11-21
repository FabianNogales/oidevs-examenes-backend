<?php

namespace App\Services\Admin;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Http\Request;

/** Authorizes explicit operations; record scope remains the responsibility of each module. */
class PermissionAuthorization
{
    public function __construct(private readonly RolePermissionCatalog $permissions) {}

    public function allows(?User $user, string $permission): bool
    {
        if (! $user || ! array_key_exists($permission, RolePermissionCatalog::ENABLED)) {
            return false;
        }
        $actor = User::find($user->id);

        return $actor?->isActive() && ! $actor->must_change_password
            && ($actor->hasRole(RoleName::ADMINISTRADOR)
                || in_array($permission, $this->permissions->effective($actor), true));
    }

    public function authorize(?User $user, string $permission): void
    {
        abort_unless($this->allows($user, $permission), 403, 'No tienes permiso vigente para esta operación.');
    }

    /** Called inside the module's existing transaction, before writing business data. */
    public function authorizeWrite(Request $request, string $permission): User
    {
        $actor = User::whereKey($request->user()->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
        if ($request->hasSession() && $actor->active_session_id) {
            abort_unless($actor->active_session_id === $request->session()->getId(), 401);
        }
        $this->authorize($actor, $permission);

        return $actor;
    }
}
