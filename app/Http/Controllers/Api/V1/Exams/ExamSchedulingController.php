<?php

namespace App\Http\Controllers\Api\V1\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\StoreExamRequest;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Services\Audit\AuditLogService;
use App\Services\Eligibility\ExamEligibilityInitializer;
use App\Services\Exams\ExamScheduleTime;
use App\Services\Rooms\RoomAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ExamSchedulingController extends Controller
{
    public function store(StoreExamRequest $request, int $courseOfferingId): JsonResponse
    {
        $courseOffering = CourseOffering::findOrFail($courseOfferingId);
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();

        // Prevención IDOR actualizada
        if (! $teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $exam = DB::transaction(function () use ($courseOffering, $request) {
            $isSharedRoom = $request->boolean('is_shared_room');
            $sharedConsent = $request->boolean('shared_consent');
            $allowSharing = $isSharedRoom || $sharedConsent;

            $primaryRoomId = (int) $request->validated('room_id');
            $additionalRoomIds = collect($request->input('additional_room_ids', []))
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0 && (int) $id !== $primaryRoomId)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $allRoomIds = array_merge([$primaryRoomId], $additionalRoomIds);
            $availabilityService = app(RoomAvailabilityService::class);

            foreach ($allRoomIds as $roomIdToReserve) {
                $availabilityService->ensureReservable(
                    $roomIdToReserve,
                    $request->validated('exam_date'),
                    $request->validated('start_time'),
                    (int) $request->validated('duration_minutes'),
                    null,
                    $allowSharing
                );
            }

            // Reservation may wait for a lock; validate again at insertion time.
            if (! ExamScheduleTime::isFutureStart($request->validated('exam_date'), $request->validated('start_time'))) {
                throw ValidationException::withMessages(['start_time' => ExamScheduleTime::FUTURE_START_ERROR]);
            }

            $evaluationType = $request->validated('evaluation_type');
            $rawPartialNumber = $request->input('partial_number');
            $partialNumber = null;

            if ($evaluationType === 'partial') {
                if (is_numeric($rawPartialNumber)) {
                    $partialNumber = min(6, max(1, (int) $rawPartialNumber));
                } elseif (preg_match('/([1-6])/', (string) $request->validated('name'), $matches)) {
                    $partialNumber = (int) $matches[1];
                }
            }

            $exam = Exam::create([
                'course_offering_id' => $courseOffering->id,
                'room_id' => $primaryRoomId,
                'evaluation_type' => $evaluationType,
                'name' => $request->validated('name'),
                'exam_date' => $request->validated('exam_date'),
                'start_time' => $request->validated('start_time'),
                'duration_minutes' => $request->validated('duration_minutes'),
                'rules' => $request->validated('rules'),
                'status' => 'SCHEDULED',
                'created_by' => $request->user()->id,
            ]);

            // Actualiza las nuevas columnas en exams sin depender de $fillable en el modelo
            if (Schema::hasColumn('exams', 'is_shared_room') || Schema::hasColumn('exams', 'partial_number')) {
                DB::table('exams')->where('id', $exam->id)->update([
                    'partial_number' => $partialNumber,
                    'is_shared_room' => $allowSharing,
                ]);
                $exam->refresh();
            }

            // Inserta todas las aulas asignadas (principal y adicionales) en exam_rooms
            if (Schema::hasTable('exam_rooms')) {
                $now = now();
                $pivotRows = [];
                foreach ($allRoomIds as $index => $assignedRoomId) {
                    $pivotRows[] = [
                        'exam_id' => $exam->id,
                        'room_id' => $assignedRoomId,
                        'is_primary' => $index === 0,
                        'is_shared' => $allowSharing,
                        'shared_with_consent' => $sharedConsent,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('exam_rooms')->insert($pivotRows);
            }

            app(ExamEligibilityInitializer::class)->forExam($exam, $request->user()->id);

            app(AuditLogService::class)->log(
                $request,
                $request->user()->id,
                'WRITE',
                'Exam',
                $exam->id,
                newValues: [
                    'source' => 'APPLICATION',
                    'operation' => 'EXAM_SCHEDULED',
                    'outcome' => 'CONFIRMED',
                    'course_offering_id' => $exam->course_offering_id,
                    'room_id' => $exam->room_id,
                    'additional_room_ids' => $additionalRoomIds,
                    'is_shared_room' => $allowSharing,
                    'partial_number' => $partialNumber,
                    'exam_date' => $exam->exam_date,
                    'start_time' => $exam->start_time,
                    'duration_minutes' => $exam->duration_minutes,
                    'evaluation_type' => $exam->evaluation_type,
                    'status' => $exam->status,
                ]
            );

            return $exam;
        });

        return response()->json([
            'message' => 'Exam scheduled successfully.',
            'data' => $exam,
        ], 201);
    }
}