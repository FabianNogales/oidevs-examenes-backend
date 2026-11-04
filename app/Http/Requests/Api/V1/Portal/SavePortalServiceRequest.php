<?php

namespace App\Http\Requests\Api\V1\Portal;

use App\Rules\InstitutionalServiceUrl;
use App\Rules\PortalServicePlainText;
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
            'name' => [$presence, 'required', 'string', 'max:200', new PortalServicePlainText],
            'description' => [$presence, 'required', 'string', 'max:2000', new PortalServicePlainText],
            'icon' => [$presence, 'required', 'string', Rule::in(config('portal_services.icon_keys', []))],
            'url' => [$presence, 'required', 'string', 'max:2048', new InstitutionalServiceUrl],
            'display_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'category_id' => ['prohibited'],
            'icon_key' => ['prohibited'],
            'destination_type' => ['prohibited'],
            'destination' => ['prohibited'],
            'requires_auth' => ['prohibited'],
            'sort_order' => ['prohibited'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
