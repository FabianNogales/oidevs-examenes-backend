<?php

namespace App\Http\Resources\AcademicTerms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicTermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->id, 'name' => $this->name,
            'start_date' => $this->start_date->toDateString(), 'end_date' => $this->end_date->toDateString(), 'status' => $this->status];
    }
}
