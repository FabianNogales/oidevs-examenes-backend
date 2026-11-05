<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;

class StudentDetailResource extends StudentResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'identity_number' => $this->identity_number,
            'profile_photo_url' => ($this->user?->profile_image_id || $this->user?->profile_photo) ? route('students.profile-photo.admin', ['student' => $this->id, 'v' => $this->user?->profile_image_id ?? 'legacy']) : null,
            'historical_photo_url' => ($this->historical_image_id || $this->historical_photo) ? route('students.historical-photo.show', ['student' => $this->id, 'v' => $this->historical_image_id ?? 'legacy']) : null,
            'historical_photo_revision' => (int) $this->historical_photo_revision,
        ]);
    }
}
