<?php

namespace App\Services\Exams;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AcademicTerm;
use App\Models\Exam;
use App\Models\User;
use Carbon\CarbonImmutable;

class ExamManagementPolicy
{
    public const TIMEZONE = 'America/La_Paz';

    // ACTIVE is already used as a scheduled legacy exam by QR and room availability.
    public const SCHEDULED_STATUSES = ['SCHEDULED', 'ACTIVE'];

    public function canView(User $actor, Exam $exam): bool
    {
        $actor->loadMissing('roles');
        $exam->loadMissing('courseOffering.teacher');
        $hasRole = $actor->roles->contains(fn ($role) => $role->name === RoleName::DOCENTE->value
            && $role->status === 'ACTIVE' && $role->pivot->status === 'ACTIVE');

        return $actor->isActive() && $hasRole && $exam->courseOffering?->teacher !== null
            && (int) $exam->courseOffering->teacher->user_id === (int) $actor->id;
    }

    public function startsAt(Exam $exam): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $exam->exam_date, 0, 10).' '.$exam->start_time, self::TIMEZONE);
    }

    public function endsAt(Exam $exam): CarbonImmutable
    {
        return $this->startsAt($exam)->addMinutes($exam->duration_minutes);
    }

    public function permissions(Exam $exam, User $actor, ?bool $singleActivePeriod = null): array
    {
        $reason = $this->blockReason($exam, $actor, $singleActivePeriod);

        return ['can_edit' => $reason === null, 'edit_block_reason' => $reason,
            'can_cancel' => $reason === null, 'cancel_block_reason' => $reason];
    }

    private function blockReason(Exam $exam, User $actor, ?bool $singleActivePeriod): ?string
    {
        if (! $this->canView($actor, $exam)) {
            return 'No tienes autorización para gestionar este examen.';
        }
        if ($exam->status === 'CANCELLED') {
            return 'El examen está cancelado.';
        }
        if (! in_array($exam->status, self::SCHEDULED_STATUSES, true)) {
            return 'El estado actual del examen solo permite su consulta.';
        }
        if ($this->startsAt($exam)->lessThanOrEqualTo(CarbonImmutable::now(self::TIMEZONE))) {
            return 'El examen ya comenzó.';
        }
        if ($actor->must_change_password) {
            return 'Debes cambiar tu contraseña antes de gestionar el examen.';
        }
        $exam->loadMissing(['courseOffering.subject', 'courseOffering.academicTerm']);
        $offering = $exam->courseOffering;
        if ($offering->teacher->status !== UserStatus::ACTIVE || $offering->status !== 'ACTIVE'
            || $offering->subject?->status !== 'ACTIVE' || $offering->academicTerm?->status !== 'ACTIVE') {
            return 'La oferta, el docente, la materia o el periodo no están habilitados para gestionar el examen.';
        }
        $singleActivePeriod ??= AcademicTerm::where('status', 'ACTIVE')->limit(2)->get(['id'])->count() === 1;
        if (! $singleActivePeriod) {
            return 'Debe existir un único periodo académico activo para gestionar el examen.';
        }

        return null;
    }
}
