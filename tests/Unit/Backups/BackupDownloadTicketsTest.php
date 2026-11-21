<?php

namespace Tests\Unit\Backups;

use App\Models\Backup;
use App\Services\Backups\BackupDownloadTickets;
use Tests\TestCase;

class BackupDownloadTicketsTest extends TestCase
{
    public function test_ticket_is_short_lived_signed_and_contains_only_authorized_reference(): void
    {
        config(['backups.download' => ['enabled' => true, 'worker_url' => 'https://worker.example.invalid/download',
            'issuer' => 'issuer-test', 'audience' => 'eida-backup-download', 'key_id' => 'kid-test',
            'signing_secret' => str_repeat('s', 48), 'ttl_seconds' => 60]]);
        $backup = new Backup(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'storage_file_id' => 'dump-id',
            'storage_parent_id' => 'folder-id', 'filename' => 'eida-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.dump',
            'bytes' => 80_000_000, 'sha256' => str_repeat('a', 64), 'storage_version' => '1']);
        $signer = new BackupDownloadTickets;
        $first = $signer->issue($backup);
        $second = $signer->issue($backup);
        [$header, $payload, $signature] = explode('.', $first['ticket']);
        $decode = fn ($text) => base64_decode(strtr($text, '-_', '+/'));
        $claims = json_decode($decode($payload), true);
        $this->assertSame(60, $claims['exp'] - $claims['iat']);
        $this->assertNotSame($first['jti'], $second['jti']);
        $this->assertSame('dump-id', $claims['file_id']);
        $this->assertSame('HS256', json_decode($decode($header), true)['alg']);
        $this->assertSame(hash_hmac('sha256', $header.'.'.$payload, str_repeat('s', 48), true), $decode($signature));
        foreach (['refresh_token', 'access_token', 'client_secret', 'password'] as $secret) {
            $this->assertArrayNotHasKey($secret, $claims);
        }
    }
}
