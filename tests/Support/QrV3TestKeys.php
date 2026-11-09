<?php

namespace Tests\Support;

class QrV3TestKeys
{
    public static function rsa(int $bits = 2048): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits,
            'config' => __DIR__.'/qr-v3-openssl.cnf']);
        if (! $key) {
            throw new \RuntimeException('Ephemeral RSA key generation failed.');
        }

        return $key;
    }
}
