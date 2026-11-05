<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Obtenemos el usuario recargado
        $user = $this->user;
        $profilePhoto = $user?->profile_image_id || $user?->profile_photo;

        return [
            'profile_photo_url' => $profilePhoto
                ? route('students.profile-photo', ['v' => $user?->profile_image_id ?? 'legacy'])
                : null,
            'personal_data' => [
                'first_names' => $this->first_names,
                'last_names' => $this->last_names,
                'identity_number' => $this->identity_number,
            ],
            'academic_data' => [
                'sis_code' => $this->sis_code,
                'email' => $user?->email,
                'career_code' => $this->career?->code,
                'career_name' => $this->career?->name,
            ],
        ];
    }
}
