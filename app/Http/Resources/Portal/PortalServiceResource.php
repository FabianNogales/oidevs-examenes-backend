<?php

namespace App\Http\Resources\Portal;

use App\Services\Portal\PortalServiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('portal.admin.*');
        $issues = $admin ? app(PortalServiceCatalog::class)->issues($this->resource) : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon_key,
            'url' => $this->destination,
            'display_order' => $this->sort_order,
            'status' => $this->when($admin, $this->status),
            'revision' => $this->when($admin, $this->revision),
            'created_by' => $this->when($admin, $this->created_by),
            'updated_by' => $this->when($admin, $this->updated_by),
            'created_at' => $this->when($admin, $this->created_at?->toISOString()),
            'updated_at' => $this->when($admin, $this->updated_at?->toISOString()),
            'needs_review' => $this->when($admin, $issues !== []),
            'validation_issues' => $this->when($admin, $issues),
        ];
    }
}
