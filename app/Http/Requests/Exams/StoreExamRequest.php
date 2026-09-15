<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'exam_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'duration_minutes' => ['required', 'integer', 'gt:0'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'rules' => ['nullable', 'string']
        ];
    }
}