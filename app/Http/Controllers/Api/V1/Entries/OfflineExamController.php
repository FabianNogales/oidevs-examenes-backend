<?php

namespace App\Http\Controllers\Api\V1\Entries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offline\SyncOfflineEntriesRequest;
use App\Services\Offline\OfflineAssignmentService;
use App\Services\Offline\OfflinePackageService;
use App\Services\Offline\OfflineSyncService;
use App\Services\Offline\MobileOfflinePackageService;
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
        $data = $request->query('schema_version') === '2'
            ? app(MobileOfflinePackageService::class)->download($exam_id, $request)
            : $service->download($exam_id, $request);
        return response()->json(['data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }

    public function mobileContext(Request $request, int $exam_id, MobileOfflinePackageService $service): JsonResponse
    {
        return response()->json(['data' => $service->context($exam_id, $request)])->header('Cache-Control', 'private, no-store');
    }

    public function resume(Request $request, int $exam_id, string $package_id, MobileOfflinePackageService $service): JsonResponse
    {
        return response()->json(['data' => $service->resume($exam_id, $package_id, $request)])->header('Cache-Control', 'private, no-store');
    }

    public function photo(Request $request, int $exam_id, string $package_id, int $student_id, string $kind, MobileOfflinePackageService $service): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $service->photo($exam_id, $package_id, $student_id, $kind, $request);
    }

    public function sync(SyncOfflineEntriesRequest $request, int $exam_id, OfflineSyncService $service): JsonResponse
    {
        return response()->json(['data' => $service->sync($exam_id, $request->validated(), $request)]);
    }

    public function syncCapabilities(Request $request, int $exam_id, OfflineSyncService $service): JsonResponse
    {
        return response()->json(['data' => $service->capabilities($exam_id, $request)])->header('Cache-Control', 'private, no-store');
    }
}
