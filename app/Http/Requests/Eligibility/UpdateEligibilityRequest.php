<?php

namespace App\Http\Requests\Eligibility;

use App\Models\Exam;
use App\Support\Eligibility\EligibilityRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEligibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = Exam::with('courseOffering.teacher')->findOrFail($this->route('exam'));

        return $this->user()?->isActive()
            && (int) $exam->courseOffering?->teacher?->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return EligibilityRules::rules($this->all(), true);
    }

    public function messages(): array
    {
        return [
            'status.required' => 'El estado de habilitación es obligatorio.',
            'status.in' => 'El estado debe ser ELIGIBLE o INELIGIBLE.',
            'reason.required' => 'Debe ingresar el motivo cuando el estudiante es inhabilitado.',
            'reason_code.required' => 'Debe seleccionar un motivo cuando el estudiante es inhabilitado.',
            'reason_code.in' => 'El código del motivo no pertenece al catálogo.',
        ];
    }
}
