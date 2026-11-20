<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'status' => $this->status,
            'is_custom' => $this->resource->isCustom(), 'is_protected' => ! $this->resource->isCustom(),
            'revision' => $this->revision,
            'permission_keys' => $this->permissions->pluck('key')->sort()->values()->all()];
    }
}
