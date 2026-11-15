<?php

namespace App\Services\QrV3;

class QrV3Factory
{
    public function make(): QrV3Service
    {
        if (config('qr_v3.enabled') !== true) {
            throw new QrV3Exception('EXPERIMENT_DISABLED');
        }
        $publicPem = $this->pemFromEnvironment('EIDA_QR_V3_PUBLIC_KEY', 'PUBLIC KEY');
        $private = @openssl_pkey_get_private($this->pemFromEnvironment('EIDA_QR_V3_PRIVATE_KEY', 'PRIVATE KEY'));
        if (! $private) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID', 'PRIVATE_KEY_OPENSSL_REJECTED');
        }

        $kid = (string) config('qr_v3.kid');

        return $this->service(new QrV3KeyRing([$kid => $publicPem], $kid, $private));
    }

    public function makeVerifier(): QrV3Service
    {
        $kid = (string) config('qr_v3.kid');
        $publicPem = $this->pemFromEnvironment('EIDA_QR_V3_PUBLIC_KEY', 'PUBLIC KEY');

        return $this->service(new QrV3KeyRing([$kid => $publicPem]));
    }

    private function service(QrV3KeyRing $keys): QrV3Service
    {
        return new QrV3Service($keys, (string) config('qr_v3.issuer'), (string) config('qr_v3.audience'),
            (int) config('qr_v3.max_lifetime_seconds'));
    }

    private function pemFromEnvironment(string $variable, string $label): string
    {
        // Read on demand, independently of Laravel's configuration cache.
        $value = getenv($variable);
        if ($value === false) {
            $value = $_ENV[$variable] ?? $_SERVER[$variable] ?? null;
        }

        $key = $label === 'PRIVATE KEY' ? 'PRIVATE_KEY' : 'PUBLIC_KEY';
        if ($value === null) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID', $key.'_MISSING_OR_EMPTY');
        }
        if (! is_string($value)) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID', $key.'_PEM_INVALID');
        }
        $pem = trim($value, " \t\r\n");
        if ($pem === '') {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID', $key.'_MISSING_OR_EMPTY');
        }
        $begin = "-----BEGIN {$label}-----";
        $end = "-----END {$label}-----";
        if ((! str_starts_with($pem, $begin."\n") && ! str_starts_with($pem, $begin."\r\n"))
            || ! str_ends_with($pem, "\n".$end) || str_contains($pem, "\0")) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID', $key.'_PEM_INVALID');
        }

        return $pem;
    }
}
