<?php

namespace App\Http\Controllers\Api\V1\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\StoreExamRequest;
use App\Models\CourseOffering;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExamSchedulingController extends Controller
{
    private const ACTIVE_STATUS = 'ACTIVE';
    private const ELIGIBLE_STATUS = 'ELIGIBLE';

    public function store(StoreExamRequest $request, int $courseOfferingId): JsonResponse
    {
        $courseOffering = CourseOffering::findOrFail($courseOfferingId);
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();

        // Prevención IDOR actualizada
        if (!$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $exam = DB::transaction(function () use ($courseOffering, $request) {
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

            $now = now();
            $eligibleStudentIds = DB::table('enrollments')
                ->join('students', 'students.id', '=', 'enrollments.student_id')
                ->where('enrollments.course_offering_id', $courseOffering->id)
                ->where('enrollments.status', self::ACTIVE_STATUS)
                ->where('students.status', self::ACTIVE_STATUS)
                ->distinct()
                ->pluck('students.id');

            if ($eligibleStudentIds->isNotEmpty()) {
                DB::table('exam_eligibilities')->insert(
                    $eligibleStudentIds->map(fn (int $studentId) => [
                        'exam_id' => $exam->id,
                        'student_id' => $studentId,
                        'status' => self::ELIGIBLE_STATUS,
                        'reason' => null,
                        'evaluated_by' => $request->user()->id,
                        'evaluated_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => 'WRITE',
                'entity_type' => 'Exam',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => $now,
            ]);

            return $exam;
        });

        return response()->json([
            'message' => 'Exam scheduled successfully.',
            'data' => $exam
        ], 201);
    }
}
