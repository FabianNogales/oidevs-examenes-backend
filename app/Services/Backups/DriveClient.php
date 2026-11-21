<?php

namespace App\Services\Backups;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Drive API v3 over the existing HTTP client; no credentials in models or logs. */
class DriveClient
{
    public const FIELDS = 'id,name,mimeType,size,trashed,parents,shared,ownedByMe,isAppAuthorized,version,md5Checksum,sha256Checksum,appProperties,capabilities(canDownload)';

    private ?string $accessToken = null;

    public function ready(): bool
    {
        $config = config('backups.drive');

        return $config['client_id'] !== '' && $config['client_secret'] !== '' && $config['refresh_token'] !== ''
            && (bool) preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $config['folder_id']);
    }

    public function metadata(string $id): array
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $id)) {
            throw new BackupFailure('INTEGRITY_MISMATCH');
        }

        $metadata = $this->get($id, ['fields' => self::FIELDS])->json();
        if (! is_array($metadata) || ($metadata['id'] ?? null) !== $id) {
            throw new BackupFailure('INTEGRITY_MISMATCH');
        }

        return $metadata;
    }

    public function manifest(string $id): array
    {
        $response = $this->get($id, ['alt' => 'media'], true);
        $body = $response->toPsrResponse()->getBody();
        $json = '';
        try {
            while (! $body->eof() && strlen($json) <= 8192) {
                $json .= $body->read(min(1024, 8193 - strlen($json)));
            }
        } catch (Throwable) {
            throw new BackupFailure('STORAGE_UNAVAILABLE');
        } finally {
            $body->close();
        }
        try {
            $manifest = strlen($json) <= 8192 ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $manifest = null;
        }
        if (! is_array($manifest)) {
            throw new BackupFailure('INVALID_MANIFEST');
        }

        return $manifest;
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if (! $this->ready()) {
            throw new BackupFailure('STORAGE_NOT_CONFIGURED');
        }
        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('backups.drive.client_id'),
                    'client_secret' => config('backups.drive.client_secret'),
                    'refresh_token' => config('backups.drive.refresh_token'), 'grant_type' => 'refresh_token',
                ]);
        } catch (Throwable) {
            throw new BackupFailure('STORAGE_UNAVAILABLE');
        }
        if (! $response->successful()) {
            throw new BackupFailure($response->json('error') === 'invalid_grant' ? 'OAUTH_REVOKED' : 'STORAGE_UNAVAILABLE');
        }
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new BackupFailure('OAUTH_REVOKED');
        }

        return $this->accessToken = $token;
    }

    private function get(string $id, array $query, bool $stream = false): Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken($this->token())->connectTimeout(3)->timeout(8)->withoutRedirecting()
                    ->withOptions(['stream' => $stream])->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id), $query);
            } catch (BackupFailure $failure) {
                throw $failure;
            } catch (Throwable) {
                throw new BackupFailure('STORAGE_UNAVAILABLE');
            }
            if ($response->status() === 401 && $attempt === 0) {
                $response->toPsrResponse()->getBody()->close();
                $this->accessToken = null;

                continue;
            }
            if ($response->successful()) {
                return $response;
            }
            // Never read an unbounded media error body or include it in an exception.
            if ($stream) {
                $response->toPsrResponse()->getBody()->close();
            }
            $reason = $stream ? null : $response->json('error.errors.0.reason');
            $code = match (true) {
                $response->status() === 401 => 'OAUTH_REVOKED',
                $response->status() === 404 => 'FILE_MISSING',
                $response->status() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true) => 'DRIVE_RATE_LIMITED',
                in_array($reason, ['storageQuotaExceeded', 'dailyLimitExceeded', 'downloadQuotaExceeded'], true) => 'DRIVE_QUOTA_EXCEEDED',
                $response->status() === 403 => 'DRIVE_PERMISSION_DENIED',
                default => 'STORAGE_UNAVAILABLE',
            };
            throw new BackupFailure($code);
        }
        throw new BackupFailure('OAUTH_REVOKED');
    }
}
