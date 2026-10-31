<?php

namespace App\Http\Requests\Api\V1\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePortalServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'max:200'],
            'description' => [$presence, 'required', 'string', 'max:2000'],
            'category_id' => [$presence, 'required', 'integer', 'min:1'],
            'icon_key' => [$presence, 'required', 'string', 'max:50'],
            'destination_type' => [$presence, 'required', Rule::in(['INTERNAL', 'EXTERNAL'])],
            'destination' => [$presence, 'required', 'string', 'max:2048'],
            'requires_auth' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
