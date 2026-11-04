<?php

namespace App\Http\Requests\Expulsions;

use Illuminate\Foundation\Http\FormRequest;

class ListExpulsionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'student_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
