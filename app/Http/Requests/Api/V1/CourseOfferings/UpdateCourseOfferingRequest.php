<?php

namespace App\Http\Requests\Api\V1\CourseOfferings;

class UpdateCourseOfferingRequest extends BulkCourseOfferingsRequest
{
    public function rules(): array
    {
        $base = parent::rules();

        return ['teacher_id' => $base['teacher_id'], 'academic_term_id' => $base['academic_term_id'],
            'subject_id' => array_values(array_filter($base['subject_ids.*'], fn ($rule) => $rule !== 'distinct'))];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['teacher_id', 'subject_id', 'academic_term_id']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido al editar la oferta.');
            }
        });
    }

    public function messages(): array
    {
        return parent::messages() + ['subject_id.required' => 'Selecciona una materia.', 'subject_id.exists' => 'La materia seleccionada no existe.'];
    }
}
