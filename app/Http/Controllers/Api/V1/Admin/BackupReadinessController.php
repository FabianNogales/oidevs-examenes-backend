<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backups\BackupReadinessDiagnostic;
use Illuminate\Http\JsonResponse;

/** Temporary administrator-only investigation endpoint. Disable after diagnosis. */
class BackupReadinessController extends Controller
{
    public function __invoke(BackupReadinessDiagnostic $diagnostic): JsonResponse
    {
        if (! config('backups.diagnostics.enabled')) {
            return response()->json(['failed_conditions' => ['diagnostics_disabled']], 404)
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json($diagnostic->administratorReport())
            ->header('Cache-Control', 'private, no-store');
    }
}
