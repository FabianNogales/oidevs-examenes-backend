<?php

namespace App\Http\Resources\Infractions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfractionChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'action' => $this->action, 'actor_id' => $this->user_id,
            'actor_name' => $this->user?->display_name, 'recorded_at' => $this->created_at->toISOString(),
            'before' => $this->old_values, 'after' => $this->new_values];
    }
}
