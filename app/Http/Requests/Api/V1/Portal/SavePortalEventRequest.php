<?php

namespace App\Http\Requests\Api\V1\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePortalEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $date = ['date', 'date_format:Y-m-d\TH:i:sP'];

        return [
            'title' => [$presence, 'required', 'string', 'max:200'],
            'description' => [$presence, 'required', 'string', 'max:10000'],
            'category_id' => [$presence, 'required', 'integer', Rule::exists('portal_event_categories', 'id')->where('is_active', true)],
            'starts_at' => [$presence, 'required', ...$date],
            'ends_at' => [$presence, 'required', ...$date],
            'all_day' => ['sometimes', 'required', 'boolean'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'published_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
