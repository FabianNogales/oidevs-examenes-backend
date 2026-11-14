<?php

namespace Tests\Support;

trait ConfiguresQrV3Issuer
{
    private array $originalQrKeyEnvironment = [];

    private function configureQrV3Issuer(): string
    {
        $key = QrV3TestKeys::rsa();
        self::assertTrue(openssl_pkey_export($key, $privatePem, null, [
            'config' => __DIR__.'/qr-v3-openssl.cnf',
        ]));
        $publicPem = openssl_pkey_get_details($key)['key'];

        config([
            'qr_v3.enabled' => true,
            'qr_v3.kid' => 'offline-test',
            'qr_v3.issuer' => 'https://eida.example.test',
            'qr_v3.audience' => 'eida-control-qr',
            'offline_sync.qr_v3_verify_enabled' => true,
            'mobile_offline.qr_v3_issued' => true,
        ]);

        $this->setQrKeyEnvironment('EIDA_QR_V3_PRIVATE_KEY', $privatePem);
        $this->setQrKeyEnvironment('EIDA_QR_V3_PUBLIC_KEY', $publicPem);

        return $publicPem;
    }

    private function setQrKeyEnvironment(string $name, string $pem): void
    {
        if (! array_key_exists($name, $this->originalQrKeyEnvironment)) {
            $previous = getenv($name);
            $this->originalQrKeyEnvironment[$name] = $previous;
            $this->beforeApplicationDestroyed(static function () use ($name, $previous): void {
                putenv($previous === false ? $name : $name.'='.$previous);
            });
        }
        self::assertTrue(putenv($name.'='.$pem));
    }
}
