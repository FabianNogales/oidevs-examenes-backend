<?php

namespace App\Http\Controllers\Api\V1\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rooms\AvailableRoomsRequest;
use App\Services\Exams\ExamManagementService;
use App\Services\Rooms\RoomAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoomController extends Controller
{
    public function index(AvailableRoomsRequest $request, RoomAvailabilityService $availability, ExamManagementService $exams)
    {
        if ($request->has('exclude_exam_id')) {
            $exams->find($request->user(), $request->validated('exclude_exam_id'));
        }

        $validated = $request->validated();
        $rooms = $availability->available($validated);

        $hasExamRoomsTable = Schema::hasTable('exam_rooms');
        $hasSharedColumn = Schema::hasColumn('exams', 'is_shared_room');
        $hasPartialNumberColumn = Schema::hasColumn('exams', 'partial_number');

        $activeExamsQuery = DB::table('exams')
            ->whereIn('status', ['ACTIVE', 'SCHEDULED', 'IN_PROGRESS'])
            ->orderBy('exam_date')
            ->orderBy('start_time');

        if (isset($validated['exclude_exam_id'])) {
            $activeExamsQuery->where('id', '!=', $validated['exclude_exam_id']);
        }

        $activeExams = $activeExamsQuery->get();
        $examsById = $activeExams->keyBy('id');

        // Construye el mapa de horarios ocupados por aula (incluyendo aulas múltiples en exam_rooms)
        $schedulesByRoomId = [];
        foreach ($activeExams as $exam) {
            [, $end] = $availability->interval($exam->exam_date, $exam->start_time, (int) $exam->duration_minutes);
            $isShared = $hasSharedColumn
                ? (bool) $exam->is_shared_room
                : str_contains((string) ($exam->rules ?? ''), '[SHARED_ROOM]');

            $slotData = [
                'exam_id' => (int) $exam->id,
                'exam_name' => (string) $exam->name,
                'date' => (string) $exam->exam_date,
                'start' => substr((string) $exam->start_time, 0, 5),
                'end' => $end->format('H:i'),
                'is_shared' => $isShared,
            ];

            $schedulesByRoomId[$exam->room_id][$exam->id] = $slotData;
        }

        if ($hasExamRoomsTable && $examsById->isNotEmpty()) {
            $pivotRows = DB::table('exam_rooms')
                ->whereIn('exam_id', $examsById->keys())
                ->get();

            foreach ($pivotRows as $pivot) {
                $exam = $examsById->get($pivot->exam_id);
                if (! $exam) {
                    continue;
                }
                [, $end] = $availability->interval($exam->exam_date, $exam->start_time, (int) $exam->duration_minutes);
                $schedulesByRoomId[$pivot->room_id][$exam->id] = [
                    'exam_id' => (int) $exam->id,
                    'exam_name' => (string) $exam->name,
                    'date' => (string) $exam->exam_date,
                    'start' => substr((string) $exam->start_time, 0, 5),
                    'end' => $end->format('H:i'),
                    'is_shared' => (bool) $pivot->is_shared || (bool) ($exam->is_shared_room ?? false),
                ];
            }
        }

        $requestedDate = $validated['exam_date'] ?? null;

        // Metadatos del grupo: último parcial creado y estudiantes habilitados (+10 de margen)
        $meta = null;
        $courseOfferingId = $request->query('course_offering_id');
        if ($courseOfferingId && is_numeric($courseOfferingId)) {
            $offeringId = (int) $courseOfferingId;

            $enrolledCount = DB::table('enrollments')
                ->where('course_offering_id', $offeringId)
                ->where('status', 'ACTIVE')
                ->count();

            $partialExams = DB::table('exams')
                ->where('course_offering_id', $offeringId)
                ->where('evaluation_type', 'partial')
                ->whereNotIn('status', ['CANCELLED'])
                ->get();

            $maxPartialNumber = 0;
            foreach ($partialExams as $partialExam) {
                if ($hasPartialNumberColumn && ! empty($partialExam->partial_number)) {
                    $maxPartialNumber = max($maxPartialNumber, (int) $partialExam->partial_number);
                } elseif (preg_match('/([1-6])/', (string) $partialExam->name, $matches)) {
                    $maxPartialNumber = max($maxPartialNumber, (int) $matches[1]);
                } else {
                    $maxPartialNumber++;
                }
            }

            $nextPartialNumber = min(6, max(1, $maxPartialNumber + 1));

            $latestExamId = DB::table('exams')
                ->where('course_offering_id', $offeringId)
                ->whereNotIn('status', ['CANCELLED'])
                ->orderByDesc('id')
                ->value('id');

            $eligibleCount = $latestExamId
                ? DB::table('exam_eligibilities')
                    ->where('exam_id', $latestExamId)
                    ->where('status', 'ELIGIBLE')
                    ->count()
                : $enrolledCount;

            $baseStudents = $eligibleCount > 0 ? $eligibleCount : $enrolledCount;
            $safetyMargin = 10;

            $meta = [
                'next_partial_number' => $nextPartialNumber,
                'last_partial_number' => $maxPartialNumber,
                'enrolled_students_count' => $enrolledCount,
                'eligible_students_count' => $baseStudents,
                'safety_margin' => $safetyMargin,
                'recommended_capacity' => $baseStudents > 0 ? $baseStudents + $safetyMargin : 0,
            ];
        }

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