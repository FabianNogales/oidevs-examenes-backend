<?php

namespace App\Http\Resources\Portal;

use App\Services\Portal\PortalServiceOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('portal.admin.*');
        $requiresAuth = $this->requires_auth;
        if (! $admin && $this->destination_type === 'INTERNAL') {
            $option = collect(app(PortalServiceOptions::class)->options()['internal_destinations'])->firstWhere('path', $this->destination);
            $requiresAuth = $requiresAuth || ($option['requires_auth'] ?? false);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'icon_key' => $this->icon_key,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'destination_type' => $this->destination_type,
            'destination' => $this->destination,
            'requires_auth' => $requiresAuth,
            'sort_order' => $this->sort_order,
            'status' => $this->when($admin, $this->status),
            'revision' => $this->when($admin, $this->revision),
            'created_by' => $this->when($admin, $this->created_by),
            'updated_by' => $this->when($admin, $this->updated_by),
        ];
    }
}
