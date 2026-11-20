<?php

namespace App\Http\Requests\Api\V1\Rooms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:15'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])],
            'date' => ['bail', 'sometimes', 'date_format:Y-m-d', function ($attribute, $value, $fail) {
                if ($value < \Carbon\CarbonImmutable::today('America/La_Paz')->toDateString()) {
                    $fail('La fecha no puede ser anterior a hoy en America/La_Paz.');
                    return;
                }
                $term = \App\Models\AcademicTerm::where('status', 'ACTIVE')->whereDate('start_date', '<=', $value)->whereDate('end_date', '>=', $value)->first();
                if (! $term) {
                    $fail('Selecciona una fecha dentro del período académico activo.');
                }
            }],
        ];
    }
}
