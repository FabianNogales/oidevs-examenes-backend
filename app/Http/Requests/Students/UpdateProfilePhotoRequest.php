<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Debe seleccionar una imagen.',
            'photo.image'    => 'El archivo debe ser una imagen válida.',
            'photo.mimes'    => 'La foto debe tener formato JPG, PNG o WEBP.',
            'photo.max'      => 'La foto no debe superar los 2MB de peso.',
        ];
    }
}