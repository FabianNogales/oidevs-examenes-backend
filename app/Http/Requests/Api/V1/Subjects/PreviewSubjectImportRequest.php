<?php

namespace App\Http\Requests\Api\V1\Subjects;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PreviewSubjectImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['bail', 'required', 'file', 'max:10240', function ($attribute, $value, $fail) {
            if (strtolower($value->getClientOriginalExtension()) !== 'csv') {
                $fail('El archivo debe tener extensión CSV.');
            }
        }]];
    }

    public function messages(): array
    {
        return ['file.required' => 'Selecciona un archivo CSV.', 'file.file' => 'Debes enviar un archivo CSV válido.'];
    }

    protected function failedValidation(Validator $validator)
    {
        if (isset($validator->failed()['file']['Max'])) {
            throw new HttpResponseException(response()->json(['message' => 'El archivo supera el máximo de 10 MB.', 'errors' => ['file' => ['El archivo supera el máximo de 10 MB.']]], 413));
        }
        parent::failedValidation($validator);
    }
}
