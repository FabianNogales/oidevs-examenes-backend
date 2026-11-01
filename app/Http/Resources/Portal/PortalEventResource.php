<?php

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at->setTimezone('America/La_Paz')->format('Y-m-d\TH:i:sP'),
            'ends_at' => $this->ends_at->setTimezone('America/La_Paz')->format('Y-m-d\TH:i:sP'),
            'all_day' => $this->all_day,
            'location' => $this->location,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'status' => $this->status,
            'published_at' => $this->published_at?->setTimezone('America/La_Paz')->format('Y-m-d\TH:i:sP'),
            'revision' => $this->when($request->routeIs('portal.admin.*'), $this->revision),
            'created_by' => $this->when($request->routeIs('portal.admin.*'), $this->created_by),
            'updated_by' => $this->when($request->routeIs('portal.admin.*'), $this->updated_by),
        ];
    }
}
