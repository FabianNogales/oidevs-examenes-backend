<?php

namespace App\Http\Requests\Api\V1\Students;

use App\Rules\SafeRasterImage;
use Illuminate\Foundation\Http\FormRequest;

class HistoricalPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('students.historical-photo.register')) {
            return [
                'photo' => ['required', 'file', 'image', new SafeRasterImage, 'mimes:jpg,jpeg,png,webp', 'max:2048'],
                'revision' => ['prohibited'],
                'reason' => ['prohibited'],
            ];
        }

        return [
            'photo' => ['required', 'file', 'image', new SafeRasterImage, 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=1,min_height=1', 'max:5120'],
            'revision' => ['required', 'integer', 'min:0'],
            'reason' => [$this->routeIs('students.historical-photo.correct') ? 'required' : 'prohibited', 'string', 'max:500'],
        ];
    }
}
