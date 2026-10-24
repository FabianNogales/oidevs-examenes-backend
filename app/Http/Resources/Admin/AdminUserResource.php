<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->teacher ?? $this->student;

        return [
            'id' => $this->id, 'email' => $this->email, 'status' => $this->status,
            'first_names' => $profile?->first_names ?? '', 'last_names' => $profile?->last_names ?? '',
            'full_name' => $this->display_name,
            'institutional_code' => $this->teacher?->institutional_code ?? $this->student?->sis_code,
            'identity_number' => $profile?->identity_number,
            'student_id' => $this->student?->id, 'teacher_id' => $this->teacher?->id,
            'student_status' => $this->student?->getRawOriginal('status'),
            'teacher_status' => $this->teacher?->getRawOriginal('status'),
            'roles' => $this->roles->filter(fn ($role) => $role->pivot->status === 'ACTIVE')
                ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'status' => $role->status])->values(),
        ];
    }
}
