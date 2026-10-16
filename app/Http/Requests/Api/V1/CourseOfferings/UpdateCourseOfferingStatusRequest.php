<?php

namespace App\Http\Requests\Api\V1\CourseOfferings;

use Illuminate\Validation\Rule;

class UpdateCourseOfferingStatusRequest extends BulkCourseOfferingsRequest
{
    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in(['ACTIVE', 'INACTIVE'])]];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['status']) as $field) {
                $validator->errors()->add($field, 'Solo puedes modificar el estado en esta operación.');
            }
        });
    }

    public function messages(): array
    {
        return ['status.*' => 'El estado es obligatorio y debe ser ACTIVE o INACTIVE.'];
    }
}
