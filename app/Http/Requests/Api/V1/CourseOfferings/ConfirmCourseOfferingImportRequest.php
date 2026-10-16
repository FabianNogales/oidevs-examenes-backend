<?php

namespace App\Http\Requests\Api\V1\CourseOfferings;

use Illuminate\Validation\Validator;

class ConfirmCourseOfferingImportRequest extends PreviewCourseOfferingImportRequest
{
    public function rules(): array
    {
        return parent::rules() + ['preview_id' => ['bail', 'required', 'uuid']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['file', 'academic_term_id', 'preview_id']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en la confirmación.');
            }
        });
    }
}
