<?php

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalNewsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('portal.admin.*');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'content' => $this->when(! $request->routeIs('*.news.index'), $this->content),
            'image_url' => $this->image_path ? route($admin ? 'portal.admin.news.image' : 'portal.public.news.image', ['news' => $this->id]) : null,
            'published_at' => $this->published_at?->setTimezone('America/La_Paz')->format('Y-m-d\TH:i:sP'),
            'status' => $this->when($admin, $this->status),
            'revision' => $this->when($admin, $this->revision),
            'created_by' => $this->when($admin, $this->created_by),
            'updated_by' => $this->when($admin, $this->updated_by),
            'published_by' => $this->when($admin, $this->published_by),
        ];
    }
}
