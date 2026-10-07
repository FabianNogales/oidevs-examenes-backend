<?php

namespace App\Http\Requests\Eligibility;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:ELIGIBLE,INELIGIBLE'],
            'reason' => ['required_if:status,INELIGIBLE', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado de habilitación es obligatorio.',
            'status.in' => 'El estado debe ser ELIGIBLE o INELIGIBLE.',
            'reason.required_if' => 'Debe ingresar el motivo cuando el estudiante es inhabilitado.',
        ];
    }
}