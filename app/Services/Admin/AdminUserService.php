<?php

namespace App\Services\Admin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public const ACTIONS = ['USER_STATUS_CHANGED', 'USER_ROLE_ASSIGNED', 'USER_ROLE_REVOKED'];

    public function __construct(private readonly AuditLogService $audit) {}

    public function listing(array $filters)
    {
        $query = User::with(['teacher', 'student', 'roles']);
        if ($search = trim($filters['search'] ?? '')) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$term]);
                foreach (['teacher' => 'institutional_code', 'student' => 'sis_code'] as $relation => $code) {
                    $q->orWhereHas($relation, fn ($profile) => $profile->whereRaw(
                        "LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(last_names || ' ' || first_names) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER({$code}) LIKE ? ESCAPE '!'", [$term]));
                }
            });
        }

        return $query->orderBy('id')->paginate($filters['per_page'] ?? 10)->withQueryString();
    }

    public function status(User $target, string $status, Request $request): User
    {
        return $this->mutate($target, $request, function (User $user) use ($status, $request) {
            if ($status === 'INACTIVE') {
                abort_if($user->id === $request->user()->id, 409, 'No puedes desactivar tu propia cuenta.');
                $this->preserveAdministrator($user);
            }
            if ($user->status !== $status) {
                $before = ['status' => $user->status];
                // Global availability never changes a student/teacher profile's own status.
                $user->status = $status;
                $this->revoke($user);
                $this->audit->log($request, $request->user()->id, 'USER_STATUS_CHANGED', 'User', $user->id,
                    $before, ['status' => $status]);
            }

            return $user;
        });
    }

    public function role(User $target, Role $selected, bool $assign, Request $request): User
    {
        return $this->mutate($target, $request, function (User $user) use ($selected, $assign, $request) {
            $role = Role::whereKey($selected->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($role->name, array_column(RoleName::cases(), 'value'), true), 422, 'Este rol no es un rol permanente permitido.');
            $pivot = DB::table('role_user')->where('user_id', $user->id)->where('role_id', $role->id)->lockForUpdate()->first();
            $assigned = $pivot?->status === 'ACTIVE';
            abort_if($assign === $assigned, 409, $assign ? 'El rol ya está asignado.' : 'El rol no tiene una asignación activa.');
            if ($assign) {
                abort_unless($role->status === 'ACTIVE', 422, 'El rol no está activo.');
                $profile = match ($role->name) {
                    'DOCENTE' => $user->teacher,
                    'ESTUDIANTE' => $user->student,
                    default => null,
                };
                if ($role->name !== 'ADMINISTRADOR' && (! $profile || (string) ($profile->getRawOriginal('status')) !== 'ACTIVE')) {
                    throw ValidationException::withMessages(['role_id' => 'Se necesita el perfil correspondiente activo. No se crean perfiles académicos al asignar roles.']);
                }
            } elseif ($role->name === 'ADMINISTRADOR') {
                abort_if($user->id === $request->user()->id, 409, 'No puedes retirar tu propio acceso administrativo.');
                $this->preserveAdministrator($user);
            } elseif ($role->name === 'DOCENTE' && $user->teacher?->courseOfferings()->where('status', 'ACTIVE')->exists()) {
                abort(409, 'El docente mantiene ofertas activas. Deben resolverse sus responsabilidades antes de retirar el rol.');
            }
            $values = ['status' => $assign ? 'ACTIVE' : 'INACTIVE'];
            if ($assign) {
                $values += ['assigned_at' => now(), 'assigned_by' => $request->user()->id];
            }
            if ($pivot) {
                DB::table('role_user')->where('id', $pivot->id)->update($values);
            } else {
                DB::table('role_user')->insert($values + ['user_id' => $user->id, 'role_id' => $role->id]);
            }
            $this->revoke($user);
            $this->audit->log($request, $request->user()->id, $assign ? 'USER_ROLE_ASSIGNED' : 'USER_ROLE_REVOKED',
                'User', $user->id, ['role_id' => $role->id, 'role' => $role->name, 'assigned' => $assigned,
                    'assigned_at' => $pivot?->assigned_at, 'assigned_by' => $pivot?->assigned_by],
                ['role_id' => $role->id, 'role' => $role->name, 'assigned' => $assign,
                    'assigned_at' => $assign ? now()->toISOString() : $pivot?->assigned_at,
                    'assigned_by' => $assign ? $request->user()->id : $pivot?->assigned_by]);

            return $user;
        });
    }

    private function mutate(User $target, Request $request, callable $operation): User
    {
        return DB::transaction(function () use ($target, $request, $operation) {
            // Serialize administrative access changes, including concurrent grants/revocations.
            Role::where('name', 'ADMINISTRADOR')->lockForUpdate()->firstOrFail();
            $users = User::whereIn('id', [$target->id, $request->user()->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($request->user()->id);
            abort_unless($actor?->isActive() && ! $actor->must_change_password && $actor->hasRole(RoleName::ADMINISTRADOR), 403);
            if ($request->hasSession() && $actor->active_session_id) {
                abort_unless($actor->active_session_id === $request->session()->getId(), 401);
            }
            $result = $operation($users->get($target->id) ?? abort(404));

            return $result->refresh()->load(['teacher', 'student', 'roles']);
        }, 3);
    }

    private function preserveAdministrator(User $user): void
    {
        if ($user->isActive() && $user->hasRole(RoleName::ADMINISTRADOR)) {
            $others = User::whereKeyNot($user->id)->where('status', 'ACTIVE')->whereHas('activeRoles',
                fn (Builder $roles) => $roles->where('name', 'ADMINISTRADOR'))->exists();
            abort_unless($others, 409, 'Debe conservarse una cuenta administrativa activa.');
        }
    }

    private function revoke(User $user): void
    {
        $user->forceFill(['active_session_id' => Str::random(40)])->save();
        $user->tokens()->delete();
    }

    public function history(array $filters, ?User $target = null)
    {
        $query = AuditLog::with('user.teacher', 'user.student')->where('entity_type', 'User')->whereIn('action', self::ACTIONS);
        if ($target) {
            $query->where('entity_id', $target->id);
        }
        if (! empty($filters['user_id'])) {
            $query->where('entity_id', $filters['user_id']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($q) use ($search) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $q->whereRaw("LOWER(action) LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('user', fn ($actor) => $actor->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereHas('teacher', fn ($p) => $p->whereRaw("LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$term]))
                        ->orWhereHas('student', fn ($p) => $p->whereRaw("LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$term])));
                if (ctype_digit($search)) $q->orWhere('entity_id', $search);
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->withQueryString();
    }
}
