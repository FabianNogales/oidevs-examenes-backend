<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return ['is_custom' => 'boolean', 'revision' => 'integer'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function isCustom(): bool
    {
        return $this->is_custom && ! \App\Services\Admin\RolePermissionCatalog::reservedName($this->name);
    }

    /**
     * The users assigned to the role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withPivot('assigned_at', 'assigned_by', 'status');
    }
}
