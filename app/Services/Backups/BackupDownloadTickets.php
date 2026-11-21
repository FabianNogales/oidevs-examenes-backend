<?php

namespace App\Services\Backups;

use App\Models\Backup;
use Illuminate\Support\Str;

/** Signing only. Atomic redemption lives exclusively in the Worker's D1 database. */
class BackupDownloadTickets
{
    public function ready(): bool
    {
        $config = config('backups.download');
        $url = parse_url($config['worker_url']);

        return $config['enabled'] && strlen($config['signing_secret']) >= 32 && $config['issuer'] !== ''
            && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $config['key_id'])
            && is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host'])
            && ($url['path'] ?? '') === '/download' && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']);
    }

    public function issue(Backup $backup): array
    {
        if (! $this->ready()) {
            throw new BackupFailure('DOWNLOADS_DISABLED');
        }
        $config = config('backups.download');
        $now = now()->timestamp;
        $jti = (string) Str::uuid();
        $claims = ['jti' => $jti, 'backup_id' => $backup->id, 'iss' => $config['issuer'], 'aud' => $config['audience'],
            'iat' => $now, 'exp' => $now + $config['ttl_seconds'], 'file_id' => $backup->storage_file_id,
            'parent_id' => $backup->storage_parent_id, 'filename' => $backup->filename, 'bytes' => $backup->bytes,
            'sha256' => $backup->sha256, 'md5_checksum' => $backup->md5_checksum, 'storage_version' => $backup->storage_version];
        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $config['key_id']]);
        $payload = $this->encode($claims);
        $signature = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $config['signing_secret'], true));

        return ['jti' => $jti, 'method' => 'POST', 'url' => $config['worker_url'],
            'ticket' => $header.'.'.$payload.'.'.$signature,
            'expires_at' => now()->setTimestamp($claims['exp'])->toISOString(), 'filename' => $backup->filename];
    }

    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
