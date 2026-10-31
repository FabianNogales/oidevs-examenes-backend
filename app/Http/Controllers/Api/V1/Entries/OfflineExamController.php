<?php

namespace App\Http\Controllers\Api\V1\Entries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offline\SyncOfflineEntriesRequest;
use App\Services\Offline\OfflineAssignmentService;
use App\Services\Offline\OfflinePackageService;
use App\Services\Offline\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineExamController extends Controller
{
    public function prepare(Request $request, int $exam_id, OfflineAssignmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->prepare($exam_id, $request)]);
    }

    public function assignments(Request $request, int $exam_id, OfflineAssignmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->assignments($exam_id, $request)]);
    }

    public function package(Request $request, int $exam_id, OfflinePackageService $service): JsonResponse
    {
        return response()->json(['data' => $service->download($exam_id, $request)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function sync(SyncOfflineEntriesRequest $request, int $exam_id, OfflineSyncService $service): JsonResponse
    {
        return response()->json(['data' => $service->sync($exam_id, $request->validated(), $request)]);
    }
}
