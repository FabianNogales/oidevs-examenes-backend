<?php

namespace App\Services\Students;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentRegistrationService
{
    public function __construct(private readonly InitialPasswordService $initialPasswordService) {}

    /**
     * Crear cuenta, rol y perfil de forma atómica con datos validados/normalizados.
     * $context distingue IMPORT (HU05) de MANUAL (HU20) en la auditoría.
     */
    public function create(
        array $data,
        Career $career,
        ?User $actor = null,
        ?Request $request = null,
        string $context = 'MANUAL',
    ): Student {
        return DB::transaction(function () use ($data, $career, $actor, $request, $context): Student {
            $studentRole = Role::query()
                ->where('name', RoleName::ESTUDIANTE->value)
                ->where('status', UserStatus::ACTIVE->value)
                ->first();

            if (! $studentRole) {
                throw new DomainException('El rol ESTUDIANTE activo no está disponible.');
            }

            $user = User::forceCreate([
                'email' => $data['email'],
                'password' => Str::random(40),
                'status' => UserStatus::ACTIVE->value,
                'profile_photo' => $data['profile_photo'] ?? null,
            ]);

            // Compartir la regla de credencial inicial CI y cambio obligatorio con HU05.
            $this->initialPasswordService->initialize($user, $data['identity_number']);

            $user->roles()->attach($studentRole->id, [
                'assigned_at' => now(),
                'assigned_by' => $actor?->id,
                'status' => UserStatus::ACTIVE->value,
            ]);

            $student = Student::create([
                'user_id' => $user->id,
                'sis_code' => $data['sis_code'],
                'identity_number' => $data['identity_number'],
                'first_names' => $data['first_names'],
                'last_names' => $data['last_names'],
                'career_id' => $career->id,
                'status' => UserStatus::ACTIVE->value,
            ]);

            // La creación y su trazabilidad se confirman o revierten juntas.
            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => 'STUDENT_CREATED',
                'entity_type' => Student::class,
                'entity_id' => $student->id,
                'new_values' => [
                    'context' => $context,
                    'user_id' => $user->id,
                    'career_id' => $career->id,
                    'status' => UserStatus::ACTIVE->value,
                ],
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => now(),
            ]);

            return $student;
        });
    }
}
