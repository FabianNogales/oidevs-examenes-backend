<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Enums\UserStatus;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /** Cargar las relaciones requeridas por el detalle administrativo. */
    public function detail(Student $student): Student
    {
        return $student->load(['user', 'career']);
    }

    /** Sincronizar perfil/cuenta y confirmar estados, revocación y auditoría juntos. */
    public function changeStatus(Student $student, string $status, Request $request): Student
    {
        $status = UserStatus::from($status);

        return DB::transaction(function () use ($student, $status, $request): Student {
            $student->refresh()->load('user');
            $oldValues = ['status' => $student->status, 'user_status' => $student->user->status];
            $newValues = ['status' => $status->value, 'user_status' => $status->value];

            $student->forceFill(['status' => $status->value]);
            $student->user->forceFill(['status' => $status->value]);

            if ($status === UserStatus::INACTIVE) {
                // Un ID no nulo impide que una cookie anterior reviva al reactivar.
                $student->user->forceFill(['active_session_id' => Str::random(40)]);
                $student->user->tokens()->delete();
            }

            if ($student->isDirty()) {
                $student->save();
            }
            if ($student->user->isDirty()) {
                $student->user->save();
            }

            // Repetir el estado no genera auditoría; INACTIVE reafirma la revocación.
            if ($oldValues !== $newValues) {
                $this->auditLogService->log(
                    $request, $request->user()?->id, 'STUDENT_STATUS_CHANGED', Student::class,
                    $student->id, $oldValues, $newValues
                );
            }

            return $this->detail($student);
        });
    }

    /** Editar el mismo Student y su correo, revirtiendo también si falla auditoría. */
    public function update(Student $student, array $data, Request $request): Student
    {
        return DB::transaction(function () use ($student, $data, $request): Student {
            $student->load('user');
            $oldValues = $this->auditableValues($student);
            // Cambiar CI no reinicializa contraseña ni must_change_password.
            $student->fill(Arr::only($data, [
                'sis_code', 'identity_number', 'first_names', 'last_names', 'career_id',
            ]));
            $changedFields = array_keys($student->getDirty());

            if (array_key_exists('email', $data)) {
                $student->user->forceFill(['email' => $data['email']]);

                if ($student->user->isDirty('email')) {
                    $changedFields[] = 'email';
                    $student->user->save();
                }
            }

            if ($student->isDirty()) {
                $student->save();
            }

            if ($changedFields !== []) {
                $newValues = $this->auditableValues($student);
                // Registrar que cambió el CI sin copiar su valor a la auditoría.
                $newValues['changed_fields'] = $changedFields;
                $this->auditLogService->log(
                    $request, $request->user()?->id, 'STUDENT_UPDATED', Student::class,
                    $student->id, $oldValues, $newValues
                );
            }

            return $this->detail($student);
        });
    }

    private function auditableValues(Student $student): array
    {
        return [
            'sis_code' => $student->sis_code,
            'first_names' => $student->first_names,
            'last_names' => $student->last_names,
            'email' => $student->user->email,
            'career_id' => $student->career_id,
        ];
    }

    /**
     * Padrón con User/Career precargados y orden estable; no filtra por estado.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Student>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return Student::query()
            ->with(['user', 'career'])
            ->search($filters['search'] ?? null)
            ->orderBy('last_names')
            ->orderBy('first_names')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends(array_intersect_key($filters, array_flip(['search', 'per_page'])));
    }
}
