<?php

namespace App\Http\Middleware;

use App\Services\Backups\BackupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBackupExecutor
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('backups.callback_secret');
        if (! config('backups.enabled') || strlen($secret) < 32) {
            return response()->json(['code' => 'BACKUPS_DISABLED', 'message' => 'Servicio no disponible.'], 503);
        }
        $timestamp = $request->header('X-Backup-Timestamp', '');
        $nonce = $request->header('X-Backup-Nonce', '');
        $signature = $request->header('X-Backup-Signature', '');
        $body = $request->getContent();
        $canonical = $timestamp."\n".$nonce."\nPOST\n".BackupService::CALLBACK_PATH."\n".$body;
        if (strlen($body) > 16384 || ! ctype_digit($timestamp) || ! Str::isUuid($nonce)
            || abs(now()->timestamp - (int) $timestamp) > config('backups.callback_window_seconds')
            || ! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            app(BackupService::class)->record($request, 'BACKUP_CALLBACK_REJECTED', context: ['error_code' => 'EXECUTOR_UNAUTHENTICATED']);

            return response()->json(['code' => 'EXECUTOR_UNAUTHENTICATED', 'message' => 'Firma no válida.'], 401);
        }

        return $next($request);
    }
}
