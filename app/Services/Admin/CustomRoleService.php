<?php

namespace App\Services\Admin;

use App\Models\Role;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomRoleService
{
    public function __construct(private AdministratorOperation $administrator, private RolePermissionCatalog $catalog,
        private AuditLogService $audit) {}

    public function save(array $input, Request $request, ?Role $selected = null): Role
    {
        try {
            return $this->administrator->run($request, function () use ($input, $request, $selected) {
                $role = $selected ? Role::whereKey($selected->id)->lockForUpdate()->firstOrFail() : new Role;
                abort_if($selected && ! $role->isCustom(), 403, 'Los roles base y heredados están protegidos.');
                abort_if($selected && $role->revision !== (int) $input['revision'], 409, 'El rol cambió. Recarga antes de guardar.');

                $name = trim((string) preg_replace('/\s+/u', ' ', $input['name']));
                $key = RolePermissionCatalog::normalizedName($name);
                if (RolePermissionCatalog::reservedName($name) || Role::whereKeyNot($role->id ?? 0)->get(['name'])
                    ->contains(fn ($existing) => RolePermissionCatalog::normalizedName($existing->name) === $key)) {
                    throw ValidationException::withMessages(['name' => 'El nombre está reservado o ya existe.']);
                }
                $available = $this->catalog->configurable()->keyBy('key');
                foreach ($input['permission_keys'] as $permission) {
                    if (! $available->has($permission)) {
                        throw ValidationException::withMessages(['permission_keys' => 'Selecciona únicamente permisos configurables del catálogo.']);
                    }
                }
                $before = $selected ? $this->snapshot($role) : null;
                $role->forceFill(['name' => $name, 'custom_name_key' => $key, 'is_custom' => true,
                    'status' => $selected ? $role->status : 'ACTIVE', 'revision' => $selected ? $role->revision : 1])->save();
                $role->permissions()->sync(collect($input['permission_keys'])->map(fn ($permission) => $available[$permission]->id)->all());
                $after = $this->snapshot($role);
                if ($before !== $after) {
                    if ($selected) {
                        $role->increment('revision');
                        // Apply the HU22 revocation semantics to every active assignment of the edited role.
                        foreach ($role->users()->wherePivot('status', 'ACTIVE')->orderBy('users.id')->lockForUpdate()->get() as $user) {
                            $user->forceFill(['active_session_id' => Str::random(40)])->save();
                            $user->tokens()->delete();
                        }
                    }
                    $this->audit->log($request, $request->user()->id, $selected ? 'ROLE_UPDATED' : 'ROLE_CREATED',
                        'Role', $role->id, $before, $after);
                    if (($before['permission_keys'] ?? []) !== $after['permission_keys']) {
                        $this->audit->log($request, $request->user()->id, 'ROLE_PERMISSIONS_CHANGED', 'Role', $role->id,
                            ['permission_keys' => $before['permission_keys'] ?? []], ['permission_keys' => $after['permission_keys']]);
                    }
                }

                return $role->refresh()->load('permissions');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'El nombre del rol ya existe.']);
        }
    }

    private function snapshot(Role $role): array
    {
        return ['name' => $role->name, 'permission_keys' => $role->permissions()->orderBy('key')->pluck('key')->all()];
    }
}
