<?php

namespace App\Http\Resources\Teachers;

use App\Enums\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institutional_code' => $this->institutional_code,
            'identity_number' => $this->identity_number,
            'first_names' => $this->first_names,
            'last_names' => $this->last_names,
            'email' => $this->whenLoaded('user', fn () => $this->user?->email),
            'status' => $this->status instanceof UserStatus ? $this->status->value : $this->status,
        ];
    }
}
