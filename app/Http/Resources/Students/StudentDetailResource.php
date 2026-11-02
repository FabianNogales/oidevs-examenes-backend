<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;

class StudentDetailResource extends StudentResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'identity_number' => $this->identity_number,
            'profile_photo_url' => $this->user?->profile_photo ? (preg_match('#^https?://#', $this->user->profile_photo) ? $this->user->profile_photo : asset('storage/'.$this->user->profile_photo)) : null,
            'historical_photo_url' => $this->historical_photo ? route('students.historical-photo.show', ['student' => $this->id]) : null,
            'historical_photo_revision' => (int) $this->historical_photo_revision,
        ]);
    }
}
