<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\MobileDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileDeviceController extends Controller
{
    public function index(Request $request, MobileDeviceService $service): JsonResponse
    {
        return response()->json(['data' => $service->listing($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $id, MobileDeviceService $service): Response
    {
        $deviceId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($deviceId === false, 404);
        $service->revoke($request->user(), $deviceId, $request);

        return response()->noContent();
    }
}
