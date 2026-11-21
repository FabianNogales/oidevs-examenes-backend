<?php

namespace Tests\Unit\Backups;

use App\Services\Backups\GithubBackupExecutor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubBackupExecutorTest extends TestCase
{
    public function test_dispatch_only_sends_uuid_and_protected_ref(): void
    {
        config(['backups.github.repository' => 'example/backend', 'backups.github.token' => 'test-only-marker',
            'backups.github.ref' => 'backup-execution']);
        Http::preventStrayRequests();
        Http::fake(['api.github.com/*' => Http::response('', 204)]);
        $this->assertSame('ACCEPTED', (new GithubBackupExecutor)->dispatch('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        Http::assertSent(fn ($request) => $request->data() === [
            'ref' => 'backup-execution', 'inputs' => ['backup_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
        ]);
        Http::assertSentCount(1);
    }

    public function test_rejection_and_ambiguous_server_error_are_not_retried(): void
    {
        config(['backups.github.repository' => 'example/backend', 'backups.github.token' => 'test-only-marker']);
        Http::preventStrayRequests();
        Http::fakeSequence()->push('', 403)->push('', 503);
        $executor = new GithubBackupExecutor;
        $this->assertSame('REJECTED', $executor->dispatch('id'));
        $this->assertSame('UNKNOWN', $executor->dispatch('id'));
        Http::assertSentCount(2);
    }
}
