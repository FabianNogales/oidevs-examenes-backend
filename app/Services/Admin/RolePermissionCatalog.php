<?php

namespace App\Services\Admin;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RolePermissionCatalog
{
    // Effective access requires both this allowlist and the persisted enablement.
    public const ENABLED = [
        'rooms.view' => true, 'rooms.create' => true, 'rooms.update' => true,
        'subjects.view' => true, 'subjects.create' => true, 'subjects.update' => true,
        'careers.view' => true,
        'academic_terms.view' => true, 'academic_terms.create' => true, 'academic_terms.update' => true,
        'course_offerings.view' => true,
        'portal.news.update' => true, 'portal.events.update' => true,
        'portal.information.sections.update' => true, 'portal.information.faqs.update' => true,
        'portal.services.update' => true,
    ];

    public static function normalizedName(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public static function reservedName(string $name): bool
    {
        return in_array(self::normalizedName($name), [
            'ADMIN', 'ADMINISTRADOR', 'DOCENTE', 'ESTUDIANTE', 'COLABORADOR', 'COLLABORATOR',
        ], true);
    }

    public function configurable(): Collection
    {
        return Permission::whereIn('key', array_keys(self::ENABLED))->where('is_configurable', true)
            ->orderBy('id')->get();
    }

    public function effective(User $user): array
    {
        return $this->assigned($user)->whereIn('key', array_keys(array_filter(self::ENABLED)))
            ->where('is_enabled', true)->orderBy('key')->pluck('key')->all();
    }

    /** Configuration is visible to management, but is not authority to use an action. */
    public function configured(User $user): array
    {
        return $this->assigned($user)->orderBy('key')->pluck('key')->all();
    }

    private function assigned(User $user): Builder
    {
        // Query current database assignments, never a browser or loaded-role snapshot.
        return Permission::whereIn('key', array_keys(self::ENABLED))
            ->whereHas('roles', fn ($roles) => $roles
                ->where('roles.status', 'ACTIVE')->whereHas('users', fn ($users) => $users
                    ->where('users.id', $user->id)->where('users.status', 'ACTIVE')->where('role_user.status', 'ACTIVE')));
    }
}
