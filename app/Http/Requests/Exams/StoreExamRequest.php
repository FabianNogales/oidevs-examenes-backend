<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

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
            'start_time' => [
                'required', 
                'date_format:H:i:s',
                function ($attribute, $value, $fail) {
                    $date = $this->input('exam_date');
                    if ($date) {
                        $examDateTime = Carbon::parse($date . ' ' . $value);
                        if ($examDateTime->isPast()) {
                            $fail('La hora del examen no puede estar en el pasado si se programa para hoy.');
                        }
                    }
                },
            ],
            
            'duration_minutes' => ['required', 'integer', 'gt:0'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'evaluation_type' => ['required', 'string', 'in:partial,final,makeup'],
            'rules' => ['nullable', 'string']
        ];
    }
}