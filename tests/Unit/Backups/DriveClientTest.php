<?php

namespace Tests\Unit\Backups;

use App\Services\Backups\BackupFailure;
use App\Services\Backups\DriveClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriveClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['backups.drive' => ['client_id' => 'test-client', 'client_secret' => 'test-secret',
            'refresh_token' => 'test-refresh', 'folder_id' => 'folder-id']]);
    }

    public function test_expired_access_token_is_refreshed_once_without_logging_or_returning_credentials(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()->push(['access_token' => 'old'])->push(['access_token' => 'new']),
            'www.googleapis.com/drive/v3/files/*' => Http::sequence()->push('', 401)->push(['id' => 'dump-id']),
        ]);
        $this->assertSame(['id' => 'dump-id'], (new DriveClient)->metadata('dump-id'));
        Http::assertSentCount(4);
    }

    public static function providerFailures(): array
    {
        return [[404, '', 'FILE_MISSING'], [429, '', 'DRIVE_RATE_LIMITED'],
            [403, 'storageQuotaExceeded', 'DRIVE_QUOTA_EXCEEDED'], [403, 'forbidden', 'DRIVE_PERMISSION_DENIED'],
            [503, '', 'STORAGE_UNAVAILABLE']];
    }

    #[DataProvider('providerFailures')]
    public function test_errors_are_safe_and_specific(int $status, string $reason, string $expected): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'test']),
            'www.googleapis.com/drive/v3/files/*' => Http::response(['error' => ['errors' => [['reason' => $reason]]]], $status)]);
        try {
            (new DriveClient)->metadata('dump-id');
            $this->fail('Provider error accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame($expected, $failure->safeCode);
        }
    }

    public function test_revoked_refresh_token_fails_closed(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        try {
            (new DriveClient)->metadata('dump-id');
            $this->fail('Revoked token accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('OAUTH_REVOKED', $failure->safeCode);
        }
        Http::assertSentCount(1);
    }
}
