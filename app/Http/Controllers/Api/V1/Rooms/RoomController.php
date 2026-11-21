<?php

namespace App\Http\Controllers\Api\V1\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rooms\AvailableRoomsRequest;
use App\Services\Exams\ExamManagementService;
use App\Services\Rooms\RoomAvailabilityService;
use App\Services\Rooms\RoomCatalogContextService;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    public function index(
        AvailableRoomsRequest $request,
        RoomAvailabilityService $availability,
        ExamManagementService $exams,
        RoomCatalogContextService $context
    ): JsonResponse {
        if ($request->has('exclude_exam_id')) {
            $exams->find($request->user(), $request->validated('exclude_exam_id'));
        }

        $validated = $request->validated();
        $meta = isset($validated['course_offering_id'])
            ? $context->offeringMeta($request->user(), (int) $validated['course_offering_id'])
            : null;
        $rooms = $availability->available($validated);
        $excludeExamId = isset($validated['exclude_exam_id']) ? (int) $validated['exclude_exam_id'] : null;
        $schedulesByRoomId = $context->schedulesByRoomId($request->user(), $excludeExamId);
        $requestedDate = $validated['exam_date'] ?? null;

        return response()->json([
            'data' => $rooms->map(function ($room) use ($schedulesByRoomId, $requestedDate) {
                $allSchedules = array_values($schedulesByRoomId[$room->id] ?? []);
                $busySlotsForSelectedDate = $requestedDate
                    ? array_values(array_filter($allSchedules, fn ($slot) => $slot['date'] === $requestedDate))
                    : [];

                return [
                    'id' => $room->id,
                    'code' => $room->code,
                    'name' => $room->name,
                    'location' => $room->location,
                    'floor' => $room->floor,
                    'capacity' => $room->capacity,
                    'status' => $room->status,
                    'busy_slots' => $busySlotsForSelectedDate,
                    'schedules_list' => $allSchedules,
                ];
            }),
            'meta' => $meta,
        ]);
    }
}
