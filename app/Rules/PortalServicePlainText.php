<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PortalServicePlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/<[^>]*>|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            $fail('Utiliza texto plano sin etiquetas HTML ni instrucciones ejecutables.');
        }
    }
}
