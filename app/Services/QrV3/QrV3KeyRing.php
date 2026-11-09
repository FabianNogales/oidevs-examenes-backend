<?php

namespace App\Services\QrV3;

use OpenSSLAsymmetricKey;

class QrV3KeyRing
{
    private array $publicKeys = [];

    public function __construct(array $publicKeys, private ?string $activeKid = null,
        private ?OpenSSLAsymmetricKey $privateKey = null)
    {
        foreach ($publicKeys as $kid => $pem) {
            if (! is_string($kid) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $kid) || ! is_string($pem)
                || ! str_starts_with($pem, '-----BEGIN PUBLIC KEY-----')) {
                throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
            }
            $key = @openssl_pkey_get_public($pem);
            $details = $key ? openssl_pkey_get_details($key) : false;
            if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
            }
            $this->publicKeys[$kid] = $key;
        }
        if ($privateKey !== null) {
            $details = openssl_pkey_get_details($privateKey);
            $public = isset($this->publicKeys[$activeKid ?? '']) ? openssl_pkey_get_details($this->publicKeys[$activeKid]) : false;
            if (! $details || ! $public || $details['type'] !== OPENSSL_KEYTYPE_RSA
                || $details['bits'] < 2048 || ! hash_equals($public['key'], $details['key'])) {
                throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
            }
        }
    }

    public function verificationKey(string $kid): OpenSSLAsymmetricKey
    {
        return $this->publicKeys[$kid] ?? throw new QrV3Exception('UNKNOWN_KID');
    }

    public function signingKey(): OpenSSLAsymmetricKey
    {
        return $this->privateKey ?? throw new QrV3Exception('SIGNING_DISABLED');
    }

    public function signingKid(): string
    {
        return $this->activeKid ?? throw new QrV3Exception('SIGNING_DISABLED');
    }

    public function __debugInfo(): array
    {
        return ['kids' => array_keys($this->publicKeys), 'signing_enabled' => $this->privateKey !== null];
    }
}
