<?php

namespace App\Http\Requests\Api\V1\Portal;

use App\Rules\PortalInformationLink;
use Illuminate\Foundation\Http\FormRequest;

class SavePortalInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $faq = $this->routeIs('portal.admin.information.faqs.*');
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = [
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'key' => ['prohibited'],
            'kind' => ['prohibited'],
            'draft' => ['prohibited'],
            'published' => ['prohibited'],
            'is_published' => ['prohibited'],
            'published_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];

        return $rules + ($faq ? [
            'question' => [$presence, 'required', 'string', 'max:500'],
            'answer' => [$presence, 'required', 'string', 'max:10000'],
            'title' => ['prohibited'],
            'description' => ['prohibited'],
            'links' => ['prohibited'],
        ] : [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'links' => ['sometimes', 'array', 'max:20'],
            'links.*' => ['required', 'array:label,url'],
            'links.*.label' => ['required', 'string', 'max:100'],
            'links.*.url' => ['required', 'string', 'max:2048', new PortalInformationLink],
            'question' => ['prohibited'],
            'answer' => ['prohibited'],
        ]);
    }
}
