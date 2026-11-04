<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class ExportExamIncidentsRequest extends ListIncidentsRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'page' => ['prohibited'], 'per_page' => ['prohibited'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), ['format', 'search', 'type', 'status', 'date', 'date_from', 'date_to', 'origin', 'student_id']) as $field) {
                $validator->errors()->add($field, 'Este parámetro no está permitido en la exportación.');
            }
        });
    }
}
