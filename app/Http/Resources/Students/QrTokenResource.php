<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'student_id'   => $this->student_id,
            'token'        => $this->signed_qr_token ?? $this->token,
            'status'       => $this->status,
            'generated_at' => $this->generated_at ? $this->generated_at->toIso8601String() : null,
        ];
    }
}