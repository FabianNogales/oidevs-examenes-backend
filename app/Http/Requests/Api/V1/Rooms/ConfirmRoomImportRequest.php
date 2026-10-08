<?php

namespace App\Http\Requests\Api\V1\Rooms;

class ConfirmRoomImportRequest extends PreviewRoomImportRequest
{
    public function rules(): array
    {
        return parent::rules() + ['preview_id' => ['required', 'string', 'uuid']];
    }
}
