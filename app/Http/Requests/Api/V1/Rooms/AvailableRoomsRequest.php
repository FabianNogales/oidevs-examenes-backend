<?php

namespace App\Http\Requests\Api\V1\Rooms;

use Illuminate\Foundation\Http\FormRequest;

class AvailableRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_date' => ['required_with:start_time,duration_minutes', 'date_format:Y-m-d'],
            'start_time' => ['required_with:exam_date,duration_minutes', 'date_format:H:i,H:i:s'],
            'duration_minutes' => ['required_with:exam_date,start_time', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
