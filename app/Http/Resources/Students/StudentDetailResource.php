<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;

class StudentDetailResource extends StudentResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'identity_number' => $this->identity_number,
        ]);
    }
}
