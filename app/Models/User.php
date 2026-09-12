<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'active_session_id',
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The student profile linked to the user.
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * The teacher profile linked to the user.
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * The roles assigned to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('assigned_at', 'assigned_by', 'status');
    }

    /**
     * The active roles assigned to the user through an active assignment.
     */
    public function activeRoles(): BelongsToMany
    {
        return $this->roles()
            ->where('roles.status', UserStatus::ACTIVE->value)
            ->wherePivot('status', UserStatus::ACTIVE->value);
    }

    public function hasRole(string|RoleName $role): bool
    {
        $roleName = $role instanceof RoleName ? $role->value : $role;

        return $this->activeRoles()
            ->where('roles.name', $roleName)
            ->exists();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE->value;
    }
}
