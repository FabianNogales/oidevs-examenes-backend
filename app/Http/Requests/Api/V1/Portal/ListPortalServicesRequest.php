<?php

namespace App\Http\Requests\Api\V1\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPortalServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
