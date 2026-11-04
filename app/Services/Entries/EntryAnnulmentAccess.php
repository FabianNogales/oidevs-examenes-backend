<?php

namespace App\Services\Entries;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\User;

class EntryAnnulmentAccess
{
    public function allows(Exam $exam, ?User $actor): bool
    {
        return $actor !== null && $actor->isActive() && $actor->hasRole(RoleName::DOCENTE)
            && (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id;
    }
}
