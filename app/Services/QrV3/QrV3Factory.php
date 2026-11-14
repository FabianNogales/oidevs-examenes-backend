<?php

namespace App\Services\QrV3;

class QrV3Factory
{
    public function make(): QrV3Service
    {
        if (config('qr_v3.enabled') !== true) {
            throw new QrV3Exception('EXPERIMENT_DISABLED');
        }
        $publicPath = realpath((string) config('qr_v3.public_key_path'));
        if (! $publicPath || ! is_file($publicPath) || ! is_readable($publicPath)) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
        }
        $private = null;
        $privatePath = (string) config('qr_v3.private_key_path');
        if ($privatePath !== '') {
            $resolved = realpath($privatePath);
            $root = rtrim(str_replace('\\', '/', realpath(base_path())), '/').'/';
            if (! $resolved || ! is_file($resolved) || ! is_readable($resolved)
                || str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower($root))) {
                throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
            }
            $privatePem = @file_get_contents($resolved);
            $private = is_string($privatePem) ? @openssl_pkey_get_private($privatePem) : false;
            if (! $private) {
                throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
            }
        }
        $publicPem = @file_get_contents($publicPath);
        if (! is_string($publicPem)) {
            throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
        }
        $kid = (string) config('qr_v3.kid');
        $ring = new QrV3KeyRing([$kid => $publicPem], $private ? $kid : null, $private ?: null);

        return new QrV3Service($ring, (string) config('qr_v3.issuer'), (string) config('qr_v3.audience'),
            (int) config('qr_v3.max_lifetime_seconds'));
    }
}
