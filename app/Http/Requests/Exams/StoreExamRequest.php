<?php

namespace App\Http\Requests\Exams;

use App\Enums\EvaluationType;
use App\Services\Exams\ExamSchedulePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
                'bail',
                'required',
                'string',
                'date_format:H:i:s',
                Rule::in(ExamSchedulePolicy::ALLOWED_START_TIMES),
            ],
            'duration_minutes' => [
                'bail',
                'required',
                'integer',
                'min:'.ExamSchedulePolicy::MIN_DURATION_MINUTES,
                'max:'.ExamSchedulePolicy::MAX_DURATION_MINUTES,
            ],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'evaluation_type' => ['required', 'string', EvaluationType::validationRule()],
            'partial_number' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:6'],
            'is_shared_room' => ['sometimes', 'boolean'],
            'shared_consent' => ['sometimes', 'boolean'],
            'additional_room_ids' => ['sometimes', 'array'],
            'additional_room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
            'rules' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            app(ExamSchedulePolicy::class)->validateScheduleWindow(
                $validator,
                $this->input('exam_date'),
                $this->input('start_time'),
                $this->input('duration_minutes'),
            );
        });
    }

    public function messages(): array
    {
        return [
            'start_time.in' => ExamSchedulePolicy::INVALID_START_TIME_ERROR,
            'duration_minutes.min' => ExamSchedulePolicy::INVALID_DURATION_ERROR,
            'duration_minutes.max' => ExamSchedulePolicy::INVALID_DURATION_ERROR,
            'evaluation_type.in' => EvaluationType::validationMessage(),
        ];
    }
}
