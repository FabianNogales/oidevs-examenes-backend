<?php

namespace App\Http\Requests\Entries;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AnnulEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
            'confirmed' => [$this->routeIs('entries.annulment') ? 'prohibited' : 'required', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== true) {
                    $fail('Debes confirmar explícitamente la anulación.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowed = $this->routeIs('entries.annulment') ? ['reason'] : ['reason', 'confirmed'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en la anulación.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Indica el motivo de la anulación.',
            'reason.string' => 'El motivo debe ser texto.',
            'confirmed.required' => 'Debes confirmar explícitamente la anulación.',
        ];
    }
}
