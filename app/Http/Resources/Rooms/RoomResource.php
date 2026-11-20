<?php

namespace App\Http\Resources\Rooms;

use App\Services\Rooms\RoomAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! array_key_exists('availability', $this->resource->getAttributes())) {
            app(RoomAvailabilityService::class)->annotate(collect([$this->resource]));
        }

        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'location' => $this->location,
            'description' => $this->description,
            'capacity' => $this->capacity,
            'floor' => $this->floor,
            'building_id' => $this->building_id,
            'inactive_until' => $this->inactive_until?->toIso8601String(),
            'status' => $this->status,
            'availability' => $this->availability,
            'current_exam' => $this->current_exam,
            'availability_date' => $this->availability_date,
            'reservation_count' => $this->reservation_count,
        ];
    }
}
