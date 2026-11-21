<?php

namespace App\Services\Backups;

use App\Models\Backup;
use App\Services\Admin\AdministratorOperation;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupService
{
    public const CALLBACK_PATH = '/api/v1/backup-executor/callback';

    public function __construct(
        private BackupExecutor $executor,
        private BackupStorage $storage,
        private AuditLogService $audit,
        private BackupDownloadTickets $tickets,
    ) {}

    public function enabled(): bool
    {
        return config('backups.enabled') && strlen(config('backups.callback_secret')) >= 32
            && $this->executor->ready() && $this->storage->ready();
    }

    public function record(Request $request, string $action, ?Backup $backup = null, array $context = []): void
    {
        // audit_logs.entity_id is numeric in the existing schema: UUID goes in safe context.
        $actorId = $request->getPathInfo() === self::CALLBACK_PATH ? null : $request->user()?->id;
        $this->audit->log($request, $actorId, $action, 'Backup', null, null,
            array_filter(['backup_id' => $backup?->id, 'status' => $backup?->status, ...$context], fn ($v) => $v !== null));
    }

    public function request(Request $request): Backup
    {
        $this->reconcile($request);
        if (! $this->enabled()) {
            $this->record($request, 'BACKUP_REQUEST_REJECTED', context: ['error_code' => 'BACKUPS_DISABLED']);
            $this->reject('BACKUPS_DISABLED', 503);
        }
        $backup = app(AdministratorOperation::class)->run($request, function ($actor) use ($request) {
            if (Backup::whereNotNull('active_slot')->exists()) {
                $this->reject('BACKUP_IN_PROGRESS', 409);
            }
            if (Backup::where('requested_by', $actor->id)->where('requested_at', '>=', now()->subHour())->count()
                >= config('backups.requests_per_hour')) {
                $this->reject('BACKUP_RATE_LIMITED', 429);
            }
            $id = (string) Str::uuid();
            $filename = 'eida-'.$id.'.dump';
            $backup = Backup::create([
                'id' => $id, 'requested_by' => $actor->id, 'requester_name' => $actor->display_name,
                'type' => 'MANUAL_FULL', 'status' => 'QUEUED', 'filename' => $filename,
                'storage_provider' => 'google_drive', 'requested_at' => now(),
                'deadline_at' => now()->addMinutes(config('backups.deadline_minutes')), 'active_slot' => 1,
            ]);
            $this->record($request, 'BACKUP_REQUESTED', $backup);

            return $backup;
        });

        // Bounded HTTP dispatch only; no local queue worker or pg_dump in a request.
        $outcome = $this->executor->dispatch($backup->id);
        DB::transaction(function () use ($request, $backup, $outcome) {
            $current = Backup::whereKey($backup->id)->lockForUpdate()->firstOrFail();
            // A callback can have arrived while dispatch was returning.
            if ($current->status !== 'QUEUED') {
                return;
            }
            if ($outcome === 'REJECTED') {
                $current->forceFill(['status' => 'FAILED', 'error_code' => 'DISPATCH_FAILED',
                    'completed_at' => now(), 'active_slot' => null])->save();
            } elseif ($outcome === 'UNKNOWN') {
                $current->forceFill(['error_code' => 'DISPATCH_UNCONFIRMED'])->save();
            }
            $this->record($request, 'BACKUP_DISPATCH_'.$outcome, $current);
        });

        return $backup->refresh();
    }

    public function reconcile(Request $request): int
    {
        return DB::transaction(function () use ($request) {
            $abandoned = Backup::whereNotNull('active_slot')->where('deadline_at', '<=', now())->lockForUpdate()->get();
            foreach ($abandoned as $backup) {
                $backup->forceFill(['status' => 'FAILED', 'error_code' => 'EXECUTION_EXPIRED',
                    'completed_at' => now(), 'active_slot' => null])->save();
                $this->record($request, 'BACKUP_ABANDONED', $backup, ['error_code' => 'EXECUTION_EXPIRED']);
            }

            return $abandoned->count();
        });
    }

    public function callback(Request $request, array $payload): void
    {
        $this->reconcile($request);
        $failure = DB::transaction(function () use ($request, $payload) {
            $backup = Backup::whereKey($payload['backup_id'])->lockForUpdate()->firstOrFail();
            $nonce = $request->header('X-Backup-Nonce');
            $digest = hash('sha256', $request->getContent());
            $receipt = DB::table('backup_callback_receipts')->where('nonce', $nonce)->first();
            if ($receipt) {
                if ($receipt->backup_id !== $backup->id || ! hash_equals($receipt->body_sha256, $digest)) {
                    $this->reject('CALLBACK_REPLAY', 409);
                }

                // A retry of rejected integrity evidence must never be acknowledged as AVAILABLE.
                return $payload['status'] === 'AVAILABLE' && $backup->status === 'FAILED'
                    ? $backup->error_code : null;
            }
            $next = $payload['status'];
            $allowed = ['QUEUED' => ['RUNNING', 'FAILED'], 'RUNNING' => ['UPLOADING', 'FAILED'],
                'UPLOADING' => ['AVAILABLE', 'FAILED']];
            if ($backup->deadline_at->isPast() || ! in_array($next, $allowed[$backup->status] ?? [], true)
                || ($backup->external_run_id !== null && $backup->external_run_id !== $payload['external_run_id'])
                || Backup::where('external_run_id', $payload['external_run_id'])->where('id', '!=', $backup->id)->exists()) {
                $this->reject('INVALID_BACKUP_TRANSITION', 409);
            }
            $failure = null;
            if ($next === 'AVAILABLE') {
                try {
                    $this->storage->verify($backup, $payload);
                } catch (BackupFailure $exception) {
                    // Connectivity failures are retryable with the same nonce. Do not certify or consume it.
                    if (in_array($exception->safeCode, ['STORAGE_UNAVAILABLE', 'STORAGE_NOT_CONFIGURED', 'DRIVE_RATE_LIMITED'], true)) {
                        $this->record($request, 'BACKUP_VERIFICATION_RETRY', $backup, ['error_code' => $exception->safeCode]);

                        return $exception->safeCode;
                    }
                    $next = 'FAILED';
                    $failure = $exception->safeCode;
                }
            }
            $values = ['status' => $next, 'external_run_id' => $payload['external_run_id'], 'error_code' => null];
            if ($next === 'RUNNING') {
                $values['started_at'] = now();
            }
            if (in_array($next, ['AVAILABLE', 'FAILED'], true)) {
                $values += ['completed_at' => now(), 'active_slot' => null];
            }
            if ($next === 'AVAILABLE') {
                $values += ['bytes' => $payload['bytes'], 'sha256' => $payload['sha256'],
                    'storage_file_id' => $payload['file_id'], 'manifest_file_id' => $payload['manifest_id'],
                    'storage_parent_id' => $payload['parent_id'], 'storage_version' => $payload['storage_version'],
                    'md5_checksum' => $payload['md5_checksum'] ?? null, 'integrity_method' => $payload['integrity_method'],
                    'postgres_version' => $payload['postgres_version'],
                    'verified_at' => now()];
            } elseif ($next === 'FAILED') {
                $values['error_code'] = $failure ?? $payload['error_code'];
            }
            $backup->forceFill($values)->save();
            DB::table('backup_callback_receipts')->insert(['nonce' => $nonce, 'backup_id' => $backup->id,
                'body_sha256' => $digest, 'received_at' => now()]);
            $this->record($request, 'BACKUP_'.$next, $backup, ['error_code' => $values['error_code']]);

            return $failure;
        });
        if ($failure) {
            $this->reject($failure, in_array($failure, ['STORAGE_UNAVAILABLE', 'STORAGE_NOT_CONFIGURED', 'DRIVE_RATE_LIMITED'], true) ? 503 : 422);
        }
    }

    public function download(Request $request, Backup $backup): array
    {
        if (! $this->enabled()) {
            $this->record($request, 'BACKUP_DOWNLOAD_REJECTED', $backup, ['error_code' => 'BACKUPS_DISABLED']);
            $this->reject('BACKUPS_DISABLED', 503);
        }
        if ($backup->status !== 'AVAILABLE') {
            $this->record($request, 'BACKUP_DOWNLOAD_REJECTED', $backup);
            $this->reject('BACKUP_NOT_AVAILABLE', 409);
        }
        if (! $this->tickets->ready()) {
            $this->record($request, 'BACKUP_DOWNLOAD_REJECTED', $backup, ['error_code' => 'DOWNLOADS_DISABLED']);
            $this->reject('DOWNLOADS_DISABLED', 503);
        }
        try {
            $this->storage->verify($backup, ['storage_provider' => $backup->storage_provider,
                'file_id' => $backup->storage_file_id, 'manifest_id' => $backup->manifest_file_id,
                'parent_id' => $backup->storage_parent_id, 'bytes' => $backup->bytes,
                'sha256' => $backup->sha256, 'md5_checksum' => $backup->md5_checksum,
                'storage_version' => $backup->storage_version, 'integrity_method' => $backup->integrity_method,
                'external_run_id' => $backup->external_run_id, 'postgres_version' => $backup->postgres_version]);
            $ticket = $this->tickets->issue($backup);
        } catch (BackupFailure $failure) {
            $this->record($request, 'BACKUP_DOWNLOAD_FAILED', $backup, ['error_code' => $failure->safeCode]);
            $this->reject($failure->safeCode, 503);
        }
        $this->record($request, 'BACKUP_DOWNLOAD_TICKET_ISSUED', $backup, ['ticket_jti' => $ticket['jti']]);

        unset($ticket['jti']);

        return $ticket;
    }

    private function reject(string $code, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code, 'message' => 'No se pudo completar la operación de respaldo. Consulte el estado e intente nuevamente.',
        ], $status));
    }
}
