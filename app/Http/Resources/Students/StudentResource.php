<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sis_code' => $this->sis_code,
            'first_names' => $this->first_names,
            'last_names' => $this->last_names,
            'email' => $this->whenLoaded('user', fn () => $this->user?->email),
            'status' => $this->status,
            'user_status' => $this->whenLoaded('user', fn () => $this->user?->status),
            'career' => $this->whenLoaded('career', fn () => $this->career ? [
                'id' => $this->career->id,
                'code' => $this->career->code,
                'name' => $this->career->name,
            ] : null),
        ];
    }
}
