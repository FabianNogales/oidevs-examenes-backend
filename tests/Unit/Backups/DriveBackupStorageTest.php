<?php

namespace Tests\Unit\Backups;

use App\Models\Backup;
use App\Services\Backups\BackupFailure;
use App\Services\Backups\DriveBackupStorage;
use App\Services\Backups\DriveClient;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriveBackupStorageTest extends TestCase
{
    public static function corruptions(): array
    {
        return [
            ['file_id', 'different-file'], ['manifest_id', 'different-manifest'],
            ['sha256', str_repeat('b', 64)], ['readback_sha256', str_repeat('b', 64)],
            ['bytes', 456], ['backup_id', 'unknown'], ['external_run_id', '456.1'],
            ['pg_restore_verified', false], ['storage_version', '2'], ['parent_id', 'other-folder'],
        ];
    }

    #[DataProvider('corruptions')]
    public function test_manifest_and_private_metadata_must_agree(string $field, mixed $value): void
    {
        [$backup, $evidence, $manifest] = $this->fixture();
        $manifest[$field] = $value;
        $this->expectException(BackupFailure::class);
        $this->storage($manifest)->verify($backup, $evidence);
    }

    public function test_readback_and_native_sha256_are_checked_without_old_fifty_mb_limit(): void
    {
        [$backup, $evidence, $manifest] = $this->fixture();
        $evidence['bytes'] = $manifest['bytes'] = 80_000_000;
        $evidence['integrity_method'] = $manifest['integrity_method'] = 'DRIVE_SHA256_AND_READBACK';
        $this->storage($manifest, ['size' => '80000000', 'sha256Checksum' => $evidence['sha256']])->verify($backup, $evidence);
        $this->addToAssertionCount(1);
    }

    public static function metadataFailures(): array
    {
        return [[['trashed' => true]], [['shared' => true]], [['ownedByMe' => false]],
            [['size' => '124']], [['sha256Checksum' => str_repeat('b', 64)]],
            [['md5Checksum' => str_repeat('b', 32)]], [['parents' => ['wrong']]], [['version' => '2']]];
    }

    #[DataProvider('metadataFailures')]
    public function test_deleted_shared_or_changed_files_cannot_be_downloaded(array $overrides): void
    {
        [$backup, $evidence, $manifest] = $this->fixture();
        $this->expectException(BackupFailure::class);
        $this->storage($manifest, $overrides)->verify($backup, $evidence);
    }

    public function test_missing_manifest_is_not_available(): void
    {
        [$backup, $evidence, $manifest] = $this->fixture();
        $client = $this->client($manifest);
        $client->shouldReceive('manifest')->with('manifest-id')->andThrow(new BackupFailure('FILE_MISSING'));
        $this->expectException(BackupFailure::class);
        (new DriveBackupStorage($client))->verify($backup, $evidence);
    }

    private function fixture(): array
    {
        config(['backups.drive.folder_id' => 'private-folder']);
        $backup = new Backup(['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'filename' => 'eida-test.dump']);
        $evidence = ['storage_provider' => 'google_drive', 'file_id' => 'dump-id', 'manifest_id' => 'manifest-id',
            'parent_id' => 'private-folder', 'bytes' => 123, 'sha256' => str_repeat('a', 64),
            'md5_checksum' => null, 'storage_version' => '1', 'postgres_version' => '17.7',
            'integrity_method' => 'READBACK_SHA256', 'external_run_id' => '123.1'];
        $manifest = [...$evidence, 'backup_id' => $backup->id, 'filename' => $backup->filename,
            'format' => 'custom', 'pg_restore_verified' => true, 'readback_sha256' => $evidence['sha256']];

        return [$backup, $evidence, $manifest];
    }

    private function client(array $manifest, array $overrides = []): DriveClient
    {
        $client = Mockery::mock(DriveClient::class);
        $common = ['trashed' => false, 'shared' => false, 'ownedByMe' => true, 'isAppAuthorized' => true,
            'capabilities' => ['canDownload' => true], 'version' => '1'];
        $client->shouldReceive('metadata')->with('private-folder')->andReturn($common + [
            'mimeType' => 'application/vnd.google-apps.folder', 'appProperties' => ['eida_backup_root' => '1']]);
        foreach (['dump-id' => ['eida-test.dump', 'dump', 'application/octet-stream', '123'],
            'manifest-id' => ['eida-test.dump.manifest.json', 'manifest', 'application/json', '1024']] as $id => [$name, $kind, $mime, $size]) {
            $metadata = [...$common, 'name' => $name, 'mimeType' => $mime, 'parents' => ['private-folder'], 'size' => $size,
                'appProperties' => ['backup_id' => $manifest['backup_id'], 'run_id' => '123.1', 'kind' => $kind]];
            // UUID corruption belongs to the manifest only, not actual Drive metadata.
            $metadata['appProperties']['backup_id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $client->shouldReceive('metadata')->with($id)->andReturn($id === 'dump-id' ? [...$metadata, ...$overrides] : $metadata);
        }

        return $client;
    }

    private function storage(array $manifest, array $overrides = []): DriveBackupStorage
    {
        $client = $this->client($manifest, $overrides);
        $client->shouldReceive('manifest')->with('manifest-id')->andReturn($manifest);

        return new DriveBackupStorage($client);
    }
}
