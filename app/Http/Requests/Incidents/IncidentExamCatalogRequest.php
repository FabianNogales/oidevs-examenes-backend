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
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
