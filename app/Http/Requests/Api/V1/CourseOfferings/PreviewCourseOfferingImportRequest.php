<?php

namespace App\Http\Requests\Api\V1\CourseOfferings;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PreviewCourseOfferingImportRequest extends BulkCourseOfferingsRequest
{
    public function rules(): array
    {
        return [
            'academic_term_id' => ['bail', 'required', 'integer', 'min:1', 'exists:academic_terms,id'],
            'file' => ['bail', 'required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (strtolower($value->getClientOriginalExtension()) !== 'csv') {
                    $fail('El archivo debe tener extensión CSV.');
                }
            }],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), ['file', 'academic_term_id']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en la previsualización.');
            }
        });
    }

    public function messages(): array
    {
        return ['file.required' => 'Selecciona un archivo CSV.', 'file.file' => 'Debes enviar un archivo CSV válido.',
            'academic_term_id.*' => 'Selecciona un periodo académico existente mediante su ID entero positivo.'];
    }

    protected function failedValidation(Validator $validator)
    {
        if (isset($validator->failed()['file']['Max'])) {
            throw new HttpResponseException(response()->json(['message' => 'El archivo supera el máximo de 10 MB.',
                'code' => 'FILE_TOO_LARGE', 'errors' => ['file' => ['El archivo supera el máximo de 10 MB.']]], 413));
        }
        parent::failedValidation($validator);
    }
}
