<?php

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalInformationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->when($this->kind === 'SECTION', $this->key),
            'revision' => $this->revision,
            'is_published' => $this->is_published,
            'has_unpublished_changes' => $this->draft !== $this->published,
            'draft' => $this->draft,
            'published' => $this->published,
            'published_at' => $this->published_at?->setTimezone('America/La_Paz')->format('Y-m-d\TH:i:sP'),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
        ];
    }
}
