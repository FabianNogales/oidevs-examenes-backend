<?php

namespace App\Services\Teachers;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly InitialPasswordService $initialPasswordService) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Teacher>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(
            max((int) ($filters['per_page'] ?? 15), 1),
            self::MAX_PER_PAGE,
        );

        return Teacher::query()
            ->with('user')
            ->search($filters['search'] ?? null)
            ->orderBy('last_names')
            ->orderBy('first_names')
            ->paginate($perPage);
    }

    public function create(array $data, ?User $actor = null, ?Request $request = null): Teacher
    {
        return DB::transaction(function () use ($data, $actor, $request): Teacher {
            $user = new User;
            $user->forceFill([
                'email' => $this->normalizeEmail($data['email']),
                'password' => Str::random(40),
                'status' => UserStatus::ACTIVE->value,
            ])->save();

            $teacher = Teacher::query()->create([
                'user_id' => $user->id,
                'institutional_code' => $this->trim($data['institutional_code']),
                'identity_number' => $this->trim($data['identity_number']),
                'first_names' => $this->trim($data['first_names']),
                'last_names' => $this->trim($data['last_names']),
                'status' => UserStatus::ACTIVE->value,
            ]);

            $this->assignTeacherRole($user, $actor);
            $this->initialPasswordService->initialize($user, $teacher->identity_number);
            $this->recordAudit('TEACHER_CREATED', $teacher, $actor, $request, null, $this->auditableValues($teacher));

            return $teacher->load('user');
        });
    }

    public function update(Teacher $teacher, array $data, ?User $actor = null, ?Request $request = null): Teacher
    {
        return DB::transaction(function () use ($teacher, $data, $actor, $request): Teacher {
            $teacher->loadMissing('user');
            $oldValues = $this->auditableValues($teacher);

            $teacherFields = Arr::only($data, [
                'institutional_code',
                'identity_number',
                'first_names',
                'last_names',
            ]);

            if ($teacherFields !== []) {
                $teacher->fill(array_map(
                    fn (mixed $value): string => $this->trim($value),
                    $teacherFields,
                ));
                $teacher->save();
            }

            if (array_key_exists('email', $data)) {
                $teacher->user->forceFill([
                    'email' => $this->normalizeEmail($data['email']),
                ])->save();
            }

            $teacher->refresh()->load('user');
            $newValues = $this->auditableValues($teacher);

            $this->recordAudit('TEACHER_UPDATED', $teacher, $actor, $request, $oldValues, $newValues);

            return $teacher;
        });
    }

    public function changeStatus(
        Teacher $teacher,
        string|UserStatus $status,
        ?User $actor = null,
        ?Request $request = null,
    ): Teacher {
        return DB::transaction(function () use ($teacher, $status, $actor, $request): Teacher {
            $teacher->loadMissing('user');
            $newStatus = $status instanceof UserStatus ? $status : UserStatus::from($status);
            $oldValues = $this->auditableValues($teacher);

            $teacher->forceFill([
                'status' => $newStatus->value,
            ])->save();

            $teacher->user->forceFill([
                'status' => $newStatus->value,
            ])->save();

            $teacher->refresh()->load('user');

            $this->recordAudit(
                $newStatus === UserStatus::ACTIVE ? 'TEACHER_ACTIVATED' : 'TEACHER_DEACTIVATED',
                $teacher,
                $actor,
                $request,
                $oldValues,
                $this->auditableValues($teacher),
            );

            return $teacher;
        });
    }

    private function assignTeacherRole(User $user, ?User $actor): void
    {
        $role = Role::query()
            ->where('name', RoleName::DOCENTE->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->first();

        if (! $role) {
            throw new DomainException('El rol DOCENTE activo no esta disponible.');
        }

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'assigned_by' => $actor?->id,
            'status' => UserStatus::ACTIVE->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditableValues(Teacher $teacher): array
    {
        $teacher->loadMissing('user');

        return [
            'institutional_code' => $teacher->institutional_code,
            'first_names' => $teacher->first_names,
            'last_names' => $teacher->last_names,
            'email' => $teacher->user?->email,
            'status' => $teacher->status instanceof UserStatus ? $teacher->status->value : $teacher->status,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function recordAudit(
        string $action,
        Teacher $teacher,
        ?User $actor,
        ?Request $request,
        ?array $oldValues,
        ?array $newValues,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => Teacher::class,
            'entity_id' => $teacher->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function normalizeEmail(mixed $email): string
    {
        return strtolower($this->trim($email));
    }

    private function trim(mixed $value): string
    {
        return trim((string) $value);
    }
}
