<?php

namespace App\Http\Requests\Api\V1\CourseOfferings;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkCourseOfferingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $positiveId = function ($attribute, $value, $fail) {
            if (! is_int($value) || $value < 1) {
                $fail('El identificador debe ser un número entero positivo.');
            }
        };

        return [
            'teacher_id' => ['bail', 'required', $positiveId, 'exists:teachers,id'],
            'academic_term_id' => ['bail', 'required', $positiveId, 'exists:academic_terms,id'],
            'subject_ids' => ['required', 'array', 'list', 'min:1'],
            'subject_ids.*' => ['bail', 'required', $positiveId, 'distinct', 'exists:subjects,id'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['teacher_id', 'academic_term_id', 'subject_ids']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido al asignar materias.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'teacher_id.required' => 'Selecciona un docente.',
            'teacher_id.exists' => 'El docente seleccionado no existe.',
            'academic_term_id.required' => 'Selecciona un periodo académico.',
            'academic_term_id.exists' => 'El periodo seleccionado no existe.',
            'subject_ids.required' => 'Selecciona al menos una materia.',
            'subject_ids.array' => 'Las materias deben enviarse como una lista de identificadores.',
            'subject_ids.list' => 'Las materias deben enviarse como una lista.',
            'subject_ids.min' => 'Selecciona al menos una materia.',
            'subject_ids.*.required' => 'El identificador de materia es obligatorio.',
            'subject_ids.*.distinct' => 'No repitas materias en la misma asignación.',
            'subject_ids.*.exists' => 'La materia seleccionada no existe.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.',
            'code' => 'VALIDATION_ERROR', 'errors' => $validator->errors()->toArray()], 422));
    }
}
