<?php

namespace App\Services\Exams;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Services\Audit\AuditLogService;
use App\Services\Eligibility\ExamEligibilityInitializer;
use App\Services\Rooms\RoomAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ExamSchedulingService
{
    public function __construct(
        private readonly RoomAvailabilityService $availability,
        private readonly ExamSchedulePolicy $schedulePolicy,
        private readonly ExamRoomAssignmentService $roomAssignments,
        private readonly ExamEligibilityInitializer $eligibilityInitializer,
        private readonly AuditLogService $audit
    ) {}

    public function schedule(CourseOffering $courseOffering, array $validated, Request $request): Exam
    {
        $courseOffering->loadMissing('academicTerm');
        $this->schedulePolicy->assertWithinAcademicTerm(
            $courseOffering->academicTerm,
            (string) $validated['exam_date']
        );

        return DB::transaction(function () use ($courseOffering, $validated, $request): Exam {
            // Shared rooms are disabled; ignore is_shared_room and shared_consent.
            $allowSharing = false;

            $primaryRoomId = (int) $validated['room_id'];
            $additionalRoomIds = $this->roomAssignments->normalizeAdditionalRoomIds(
                $primaryRoomId,
                $validated['additional_room_ids'] ?? $request->input('additional_room_ids', [])
            );

            $allRoomIds = array_merge([$primaryRoomId], $additionalRoomIds);
            $roomsToReserve = $allRoomIds;
            sort($roomsToReserve, SORT_NUMERIC);

            foreach ($roomsToReserve as $roomIdToReserve) {
                $this->availability->ensureReservable(
                    $roomIdToReserve,
                    (string) $validated['exam_date'],
                    (string) $validated['start_time'],
                    (int) $validated['duration_minutes'],
                    null,
                    $allowSharing
                );
            }

            // Reservation may wait for a lock; validate again at insertion time.
            if (! ExamScheduleTime::isFutureStart((string) $validated['exam_date'], (string) $validated['start_time'])) {
                throw ValidationException::withMessages(['start_time' => ExamScheduleTime::FUTURE_START_ERROR]);
            }

            $evaluationType = (string) $validated['evaluation_type'];
            $partialNumber = $this->schedulePolicy->resolvePartialNumber(
                $evaluationType,
                $validated['partial_number'] ?? $request->input('partial_number'),
                (string) $validated['name']
            );

            $exam = Exam::create([
                'course_offering_id' => $courseOffering->id,
                'room_id' => $primaryRoomId,
                'evaluation_type' => $evaluationType,
                'name' => $validated['name'],
                'exam_date' => $validated['exam_date'],
                'start_time' => $validated['start_time'],
                'duration_minutes' => (int) $validated['duration_minutes'],
                'rules' => $validated['rules'] ?? null,
                'status' => 'SCHEDULED',
                'created_by' => $request->user()->id,
            ]);

            $hasSharedColumn = Schema::hasColumn('exams', 'is_shared_room');
            $hasPartialColumn = Schema::hasColumn('exams', 'partial_number');
            if ($hasSharedColumn || $hasPartialColumn) {
                $extraUpdates = [];
                if ($hasPartialColumn) {
                    $extraUpdates['partial_number'] = $partialNumber;
                }
                if ($hasSharedColumn) {
                    $extraUpdates['is_shared_room'] = false;
                }
                DB::table('exams')->where('id', $exam->id)->update($extraUpdates);
                $exam->refresh();
            }

            $this->roomAssignments->syncExamRooms($exam->id, $allRoomIds);

            $this->eligibilityInitializer->forExam($exam, $request->user()->id);

            $this->audit->log(
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
                    'is_shared_room' => false,
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
    }
}
