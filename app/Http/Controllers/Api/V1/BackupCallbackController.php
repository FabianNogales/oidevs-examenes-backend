<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backups\BackupCallbackRequest;
use App\Services\Backups\BackupService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

class BackupCallbackController extends Controller
{
    public function __invoke(BackupCallbackRequest $request, BackupService $service): JsonResponse
    {
        try {
            $service->callback($request, $request->validated());
        } catch (HttpResponseException|ModelNotFoundException $exception) {
            $service->record($request, 'BACKUP_CALLBACK_REJECTED');
            throw $exception;
        }

        return response()->json(['acknowledged' => true])->header('Cache-Control', 'no-store');
    }
}
