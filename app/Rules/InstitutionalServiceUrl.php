<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class InstitutionalServiceUrl implements ValidationRule
{
    public function allowed(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $decoded = $value;
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x20\x7f\\\\<>]/', $decoded)) {
            return false;
        }
        $parts = parse_url($value);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if (! preg_match('/\A[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9]+(?:[a-z0-9-]*[a-z0-9])?)+\z/D', $host)) {
            return false;
        }
        $allowed = false;
        foreach (config('portal_services.allowed_domains', []) as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && ($host === $domain || (config('portal_services.allow_subdomains', true) && str_ends_with($host, '.'.$domain)))) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            return false;
        }
        // Catalog links must not carry login/session secrets, even if manually supplied.
        foreach ([$parts['query'] ?? '', $parts['fragment'] ?? ''] as $query) {
            $query = rawurldecode(rawurldecode($query));
            foreach (preg_split('/[&;]/', $query) as $parameter) {
                $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('=', $parameter, 2)[0]));
                if (preg_match('/token|password|passwd|credential|secret|session|jwt|authorization|apikey/', $key)
                    || in_array($key, ['auth', 'bearer', 'code'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->allowed($value)) {
            $fail('La URL debe usar HTTPS y un dominio institucional autorizado, sin credenciales ni parámetros de autenticación.');
        }
    }
}
