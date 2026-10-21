<?php

namespace App\Http\Controllers\Api\V1\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rooms\AvailableRoomsRequest;
use App\Services\Exams\ExamManagementService;
use App\Services\Rooms\RoomAvailabilityService;

class RoomController extends Controller
{
    public function index(AvailableRoomsRequest $request, RoomAvailabilityService $availability, ExamManagementService $exams)
    {
        if ($request->has('exclude_exam_id')) {
            $exams->find($request->user(), $request->validated('exclude_exam_id'));
        }
        $rooms = $availability->available($request->validated());

        return response()->json(['data' => $rooms->map(fn ($room) => $room->only(['id', 'code', 'name', 'location', 'status']))]);
    }
}
