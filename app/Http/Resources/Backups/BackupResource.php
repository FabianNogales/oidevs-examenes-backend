<?php

namespace App\Http\Resources\Backups;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'requester' => $this->requester_name, 'type' => $this->type,
            'state' => $this->status, 'filename' => $this->filename, 'bytes' => $this->bytes,
            'sha256' => $this->sha256, 'postgres_version' => $this->postgres_version,
            'requested_at' => $this->requested_at?->toISOString(), 'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(), 'verified_at' => $this->verified_at?->toISOString(),
            'error_code' => $this->error_code,
        ];
    }
}
