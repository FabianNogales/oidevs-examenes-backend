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
use Illuminate\Validation\ValidationException;

class ExamSchedulingController extends Controller
{
    public function store(StoreExamRequest $request, int $courseOfferingId): JsonResponse
    {
        $courseOffering = CourseOffering::findOrFail($courseOfferingId);
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();

        // Prevención IDOR actualizada
        if (!$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $exam = DB::transaction(function () use ($courseOffering, $request) {
            app(RoomAvailabilityService::class)->ensureReservable(
                (int) $request->validated('room_id'), $request->validated('exam_date'),
                $request->validated('start_time'), (int) $request->validated('duration_minutes')
            );
            // Reservation may wait for a lock; validate again at insertion time.
            if (! ExamScheduleTime::isFutureStart($request->validated('exam_date'), $request->validated('start_time'))) {
                throw ValidationException::withMessages(['start_time' => ExamScheduleTime::FUTURE_START_ERROR]);
            }
            $exam = Exam::create([
                'course_offering_id' => $courseOffering->id,
                'room_id' => $request->validated('room_id'),
                'evaluation_type' => $request->validated('evaluation_type'),
                'name' => $request->validated('name'),
                'exam_date' => $request->validated('exam_date'),
                'start_time' => $request->validated('start_time'),
                'duration_minutes' => $request->validated('duration_minutes'),
                'rules' => $request->validated('rules'),
                'status' => 'SCHEDULED',
                'created_by' => $request->user()->id,
            ]);

            app(ExamEligibilityInitializer::class)->forExam($exam, $request->user()->id);

            app(AuditLogService::class)->log($request, $request->user()->id, 'WRITE', 'Exam', $exam->id,
                newValues: [
                    'source' => 'APPLICATION', 'operation' => 'EXAM_SCHEDULED', 'outcome' => 'CONFIRMED',
                    'course_offering_id' => $exam->course_offering_id, 'room_id' => $exam->room_id,
                    'exam_date' => $exam->exam_date, 'start_time' => $exam->start_time,
                    'duration_minutes' => $exam->duration_minutes, 'evaluation_type' => $exam->evaluation_type,
                    'status' => $exam->status,
                ]);

            return $exam;
        });

        return response()->json([
            'message' => 'Exam scheduled successfully.',
            'data' => $exam
        ], 201);
    }
}
