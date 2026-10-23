<?php

namespace App\Http\Requests\Exams;

use App\Services\Exams\ExamScheduleTime;
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
            'exam_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('America/La_Paz')->toDateString()],
            'start_time' => [
                'bail', 'required',
                'date_format:H:i:s',
                function ($attribute, $value, $fail) {
                    $date = $this->input('exam_date');
                    if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        try {
                            $isFuture = ExamScheduleTime::isFutureStart($date, $value);
                        } catch (\Throwable $exception) {
                            return;
                        }
                        if (! $isFuture) {
                            $fail(ExamScheduleTime::FUTURE_START_ERROR);
                        }
                    }
                },
            ],

            'duration_minutes' => ['required', 'integer', 'gt:0', 'max:2147483647'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'evaluation_type' => ['required', 'string', 'in:partial,final,makeup'],
            'rules' => ['nullable', 'string'],
        ];
    }
}
