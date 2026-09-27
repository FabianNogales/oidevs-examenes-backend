<?php

namespace App\Http\Requests\Enrollments;

use Illuminate\Foundation\Http\FormRequest;

class StoreBulkEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:5120', // Si existe un límite de 5MB como dice la prueba
                function ($attribute, $value, $fail) {
                    // Si el archivo no es válido de origen, dejamos que otras reglas fallen
                    if (!$value->isValid()) {
                        return;
                    }

                    $stream = fopen($value->getRealPath(), 'r');
                    $headers = fgetcsv($stream);
                    fclose($stream);

                    // Validar que se pudieron extraer cabeceras y que contenga 'sisCode'
                    if (!$headers || !in_array('sisCode', $headers)) {
                        $fail('El archivo CSV debe contener la columna obligatoria sisCode.');
                    }
                },
            ],
        ];
    }
}