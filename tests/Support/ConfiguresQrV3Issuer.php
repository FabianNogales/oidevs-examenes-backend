<?php

namespace Tests\Support;

trait ConfiguresQrV3Issuer
{
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
            'qr_v3.private_key_path' => $this->writeTemporaryQrPem($privatePem),
            'qr_v3.public_key_path' => $this->writeTemporaryQrPem($publicPem),
            'offline_sync.qr_v3_verify_enabled' => true,
            'mobile_offline.qr_v3_issued' => true,
        ]);

        return $publicPem;
    }

    private function writeTemporaryQrPem(string $pem): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eida_qr_');
        self::assertNotFalse($path);
        $this->beforeApplicationDestroyed(fn () => unlink($path));
        self::assertSame(strlen($pem), file_put_contents($path, $pem));

        return $path;
    }
}
