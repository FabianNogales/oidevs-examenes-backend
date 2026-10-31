<?php

namespace App\Http\Requests\Api\V1\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePortalNewsStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])],
            'revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
