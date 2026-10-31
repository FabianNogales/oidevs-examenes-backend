<?php

namespace App\Http\Requests\Api\V1\Portal;

use Illuminate\Foundation\Http\FormRequest;

class PortalNewsImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'image' => $this->isMethod('DELETE') ? ['prohibited'] : ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=1,min_height=1', 'max:5120'],
        ];
    }
}
