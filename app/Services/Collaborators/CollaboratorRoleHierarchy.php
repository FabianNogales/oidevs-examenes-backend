<?php

namespace App\Services\Collaborators;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CollaboratorRoleHierarchy
{
    private const LEVELS = [
        RoleName::ADMINISTRADOR->value => 3,
        RoleName::DOCENTE->value => 2,
        RoleName::ESTUDIANTE->value => 1,
    ];

    public function effectiveLevel(User $user): int
    {
        return $user->activeRoles()->pluck('roles.name')
            ->map(fn (string $name) => self::LEVELS[$name] ?? 0)->max() ?? 0;
    }

    public function constrainCandidates(Builder $query, User $actor): Builder
    {
        $level = $this->effectiveLevel($actor);
        $allowed = array_keys(array_filter(self::LEVELS, fn (int $rank) => $rank <= $level));
        $higher = array_keys(array_filter(self::LEVELS, fn (int $rank) => $rank > $level));

        return $query->whereKeyNot($actor->id)
            ->whereHas('activeRoles', fn (Builder $roles) => $roles->whereIn('roles.name', $allowed))
            ->whereDoesntHave('activeRoles', fn (Builder $roles) => $roles->whereIn('roles.name', $higher));
    }
}
