<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PortalInformationLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El enlace debe ser una ruta interna, una URL HTTPS o un correo mailto válido.');

            return;
        }
        // Reject alternate representations of protocol-relative URLs and control
        // characters before validating the destination. No server-side URL fetch.
        $decoded = $value;
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            $fail('El enlace contiene caracteres no permitidos.');

            return;
        }
        $internal = str_starts_with($decoded, '/') && ! str_starts_with($decoded, '//');
        $https = filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            && parse_url($value, PHP_URL_USER) === null
            && parse_url($value, PHP_URL_PASS) === null;
        $email = str_starts_with($value, 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false;
        if (! $internal && ! $https && ! $email) {
            $fail('El enlace debe ser una ruta interna, una URL HTTPS o un correo mailto válido.');
        }
    }
}
