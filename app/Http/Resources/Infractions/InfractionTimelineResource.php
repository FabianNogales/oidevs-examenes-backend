<?php

namespace App\Http\Resources\Infractions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfractionTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $before = $this->old_values ?? [];
        $after = $this->new_values ?? [];

        return ['id' => (int) $this->id,
            'action' => match ($this->action) {
                'INFRACTION_REPORTED' => 'CREATED', 'INFRACTION_STATUS_CHANGED' => 'STATUS_CHANGED', default => 'UPDATED',
            },
            'from_status' => $before['status'] ?? null, 'to_status' => $after['status'] ?? null,
            'reason' => $after['reason'] ?? null,
            'performed_by' => ($after['performed_by']['id'] ?? $this->user_id) === null ? null : [
                'id' => (int) ($after['performed_by']['id'] ?? $this->user_id),
                'name' => $after['performed_by']['name'] ?? $this->user?->display_name,
            ],
            'performed_at' => $after['performed_at'] ?? $this->created_at->toISOString(),
        ];
    }
}
