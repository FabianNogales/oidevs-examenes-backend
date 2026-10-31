<?php

namespace App\Http\Requests\Api\V1\Portal;

use Illuminate\Foundation\Http\FormRequest;

class SavePortalNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'required', 'string', 'max:200'],
            'summary' => [$presence, 'required', 'string', 'max:1000'],
            'content' => [$presence, 'required', 'string', 'max:50000'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'image_path' => ['prohibited'],
            'image_url' => ['prohibited'],
            'published_at' => ['prohibited'],
            'published_by' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
