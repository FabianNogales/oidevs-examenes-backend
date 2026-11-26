<?php

namespace App\Services\Demo;

use DomainException;

class DemoBackupGuard
{
    public function verify(array $options): string
    {
        $path = (string) ($options['backup'] ?? '');
        $expected = strtolower((string) ($options['backup-sha256'] ?? ''));
        if (! ($options['restore-verified'] ?? false) || ! preg_match('/\A[a-f0-9]{64}\z/', $expected)
            || ! preg_match('~\A(?:[A-Za-z]:[\\\\/]|/)~', $path) || ! is_file($path) || ! is_readable($path) || filesize($path) < 100) {
            throw new DomainException('Requiere --backup absoluto, --backup-sha256 y --restore-verified tras una restauración de prueba exitosa.');
        }
        $handle = fopen($path, 'rb');
        try {
            $header = fread($handle, 2048);
        } finally {
            fclose($handle);
        }
        if (! str_starts_with($header, 'PGDMP') && ! str_contains($header, 'PostgreSQL database dump')) {
            throw new DomainException('El archivo no parece un respaldo PostgreSQL custom o SQL.');
        }
        $hash = hash_file('sha256', $path);
        if (! hash_equals($expected, $hash)) {
            throw new DomainException('El respaldo no coincide con el SHA256 restaurado y verificado.');
        }

        return $hash;
    }
}
