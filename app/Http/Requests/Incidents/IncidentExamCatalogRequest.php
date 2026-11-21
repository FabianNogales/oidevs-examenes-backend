<?php

namespace App\Http\Requests\Incidents;

use App\Enums\RoleName;
use Illuminate\Foundation\Http\FormRequest;

class IncidentExamCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() && $this->user()->hasRole(RoleName::ADMINISTRADOR);
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'academic_term_id' => ['sometimes', 'integer', 'min:1', 'exists:academic_terms,id'],
            'subject_id' => ['sometimes', 'integer', 'min:1', 'exists:subjects,id'],
            'career_id' => ['sometimes', 'integer', 'min:1', 'exists:careers,id'],
            'course_offering_id' => ['sometimes', 'integer', 'min:1', 'exists:course_offerings,id'],
            'evaluation_type' => ['sometimes', \App\Enums\EvaluationType::validationRule()],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
