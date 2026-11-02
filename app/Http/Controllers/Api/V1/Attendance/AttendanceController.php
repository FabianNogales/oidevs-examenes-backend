<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceRequest;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function summary(AttendanceRequest $request, int $examId, AttendanceService $service): JsonResponse
    {
        return response()->json($service->snapshot($examId, $request->user(), $request->validated()))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function students(AttendanceRequest $request, int $examId, AttendanceService $service): JsonResponse
    {
        return response()->json($service->snapshot($examId, $request->user(), $request->validated(), includeStudents: true))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
