<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrentUserResource extends JsonResource
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
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->whenLoaded(
                'activeRoles',
                fn () => $this->activeRoles->pluck('name')->values()->all(),
                [],
            ),
        ];
    }
}
