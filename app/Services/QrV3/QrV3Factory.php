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
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
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
        $body = getenv($variable);
        if ($body === false) {
            $body = $_ENV[$variable] ?? $_SERVER[$variable] ?? null;
        }

        if (! is_string($body) || ! preg_match('/^[A-Za-z0-9+\/]+={0,2}\z/', $body)) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
        }
        $der = base64_decode($body, true);
        if ($der === false || base64_encode($der) !== $body) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
        }

        return "-----BEGIN {$label}-----\n".chunk_split($body, 64, "\n")."-----END {$label}-----\n";
    }
}
