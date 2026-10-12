<?php

namespace App\Services\Entries;

class QrTokenValidator
{
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! config('app.key')) {
            return null;
        }
        [$header, $payload, $signature] = array_map($this->decodePart(...), $parts);
        if ($header === null || $payload === null || $signature === null) {
            return null;
        }
        $header = json_decode($header, true);
        $payload = json_decode($payload, true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT'
            || ! is_array($payload)
            || ! hash_equals(hash_hmac('sha256', $parts[0].'.'.$parts[1], config('app.key'), true), $signature)) {
            return null;
        }
        foreach (['std', 'exm', 'exp'] as $claim) {
            if (! isset($payload[$claim]) || ! is_int($payload[$claim]) || $payload[$claim] <= 0) {
                return null;
            }
        }
        if ($payload['exp'] <= now()->timestamp || ! is_string($payload['jti'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payload['jti'])) {
            return null;
        }

        return $payload;
    }

    private function decodePart(string $part): ?string
    {
        if ($part === '' || ! preg_match('/^[A-Za-z0-9_-]+$/D', $part) || strlen($part) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
