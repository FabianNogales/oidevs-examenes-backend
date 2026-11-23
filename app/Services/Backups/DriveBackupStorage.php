<?php

namespace App\Services\Backups;

use App\Models\Backup;

class DriveBackupStorage implements BackupStorage
{
    public function __construct(private DriveClient $client) {}

    public function ready(): bool
    {
        return $this->client->ready();
    }

    public function verify(Backup $backup, array $evidence): void
    {
        $fileId = $evidence['file_id'] ?? '';
        $manifestId = $evidence['manifest_id'] ?? '';
        $folderId = config('backups.drive.folder_id');
        if (($evidence['storage_provider'] ?? null) !== 'google_drive' || $fileId === $manifestId
            || ! preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $fileId)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,200}\z/', $manifestId)
            || ($evidence['parent_id'] ?? null) !== $folderId
            || ! is_int($evidence['bytes'] ?? null) || $evidence['bytes'] < 1
            || ! preg_match('/\A[a-f0-9]{64}\z/', $evidence['sha256'] ?? '')
            || ! preg_match('/\A[0-9]{1,100}\z/', $evidence['storage_version'] ?? '')) {
            throw new BackupFailure('INTEGRITY_MISMATCH');
        }
        $folder = $this->client->metadata($folderId);
        $this->assertPrivate($folder);
        if (($folder['mimeType'] ?? null) !== 'application/vnd.google-apps.folder'
            || ($folder['appProperties']['eida_backup_root'] ?? null) !== '1') {
            throw new BackupFailure('DRIVE_PERMISSION_DENIED');
        }
        $file = $this->client->metadata($fileId);
        $manifestFile = $this->client->metadata($manifestId);
        foreach ([[$file, $backup->filename, 'dump', 'application/octet-stream'],
            [$manifestFile, $backup->filename.'.manifest.json', 'manifest', 'application/json']] as [$metadata, $name, $kind, $mime]) {
            $this->assertPrivate($metadata);
            if (($metadata['name'] ?? null) !== $name || ($metadata['mimeType'] ?? null) !== $mime
                || ($metadata['capabilities']['canDownload'] ?? false) !== true
                || ($metadata['parents'] ?? []) !== [$folderId]
                || ($metadata['appProperties']['backup_id'] ?? null) !== $backup->id
                || ($metadata['appProperties']['kind'] ?? null) !== $kind
                || ($metadata['appProperties']['run_id'] ?? null) !== $evidence['external_run_id']) {
                throw new BackupFailure('INTEGRITY_MISMATCH');
            }
        }
        if ((string) ($file['size'] ?? '') !== (string) $evidence['bytes']
            || ! preg_match('/\A[0-9]{1,100}\z/', (string) ($file['version'] ?? ''))
            || ((string) $file['version'] !== $evidence['storage_version'] && ! isset($file['sha256Checksum']))
            || (isset($file['sha256Checksum']) && ! hash_equals($evidence['sha256'], $file['sha256Checksum']))
            || (isset($file['md5Checksum']) && $file['md5Checksum'] !== ($evidence['md5_checksum'] ?? null))) {
            throw new BackupFailure('INTEGRITY_MISMATCH');
        }
        if (! ctype_digit((string) ($manifestFile['size'] ?? '')) || (int) $manifestFile['size'] < 1 || (int) $manifestFile['size'] > 8192) {
            throw new BackupFailure('INVALID_MANIFEST');
        }
        $manifest = $this->client->manifest($manifestId);
        foreach (['storage_provider', 'file_id', 'manifest_id', 'parent_id', 'bytes', 'sha256',
            'storage_version', 'external_run_id', 'postgres_version', 'integrity_method'] as $field) {
            if (($manifest[$field] ?? null) !== $evidence[$field]) {
                throw new BackupFailure('INTEGRITY_MISMATCH');
            }
        }
        if (($manifest['backup_id'] ?? null) !== $backup->id || ($manifest['filename'] ?? null) !== $backup->filename
            || ! in_array($evidence['integrity_method'] ?? null, ['DRIVE_SHA256_AND_READBACK', 'READBACK_SHA256'], true)
            || (($evidence['integrity_method'] ?? null) === 'DRIVE_SHA256_AND_READBACK' && ! isset($file['sha256Checksum']))
            || ($manifest['format'] ?? null) !== 'custom' || ($manifest['pg_restore_verified'] ?? null) !== true
            || ($manifest['readback_sha256'] ?? null) !== $evidence['sha256']
            || ($manifest['md5_checksum'] ?? null) !== ($evidence['md5_checksum'] ?? null)) {
            throw new BackupFailure('INTEGRITY_MISMATCH');
        }
        // The manifest stays bound to the executor's verified version. A later
        // dump metadata version requires an independently matching content hash.
        foreach ([[$fileId, $file], [$manifestId, $manifestFile]] as [$id, $before]) {
            $after = $this->client->metadata($id);
            $this->assertPrivate($after);
            if (($after['version'] ?? null) !== ($before['version'] ?? null)) {
                if ($id !== $fileId
                    || ! preg_match('/\A[0-9]{1,100}\z/', (string) ($after['version'] ?? ''))
                    || ! isset($after['sha256Checksum']) || ! hash_equals($evidence['sha256'], $after['sha256Checksum'])
                    || (isset($after['md5Checksum']) && $after['md5Checksum'] !== ($evidence['md5_checksum'] ?? null))
                    || ($after['capabilities']['canDownload'] ?? false) !== true) {
                    throw new BackupFailure('INTEGRITY_MISMATCH');
                }
                foreach (['name', 'mimeType', 'parents', 'size'] as $field) {
                    if (($after[$field] ?? null) !== ($before[$field] ?? null)) {
                        throw new BackupFailure('INTEGRITY_MISMATCH');
                    }
                }
                foreach (['backup_id', 'run_id', 'kind'] as $field) {
                    if (($after['appProperties'][$field] ?? null) !== ($before['appProperties'][$field] ?? null)) {
                        throw new BackupFailure('INTEGRITY_MISMATCH');
                    }
                }
            }
        }
    }

    private function assertPrivate(array $metadata): void
    {
        if (($metadata['trashed'] ?? true) !== false) {
            throw new BackupFailure('FILE_MISSING');
        }
        if (($metadata['shared'] ?? true) !== false || ($metadata['ownedByMe'] ?? false) !== true
            || ($metadata['isAppAuthorized'] ?? false) !== true) {
            throw new BackupFailure('DRIVE_PERMISSION_DENIED');
        }
    }
}
