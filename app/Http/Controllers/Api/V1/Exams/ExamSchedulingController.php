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
    public function store(StoreExamRequest $request, int $courseOfferingId): JsonResponse
    {
        $courseOffering = CourseOffering::findOrFail($courseOfferingId);
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();

        // Prevención IDOR actualizada
        if (!$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        DB::beginTransaction();

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

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'WRITE',
            'entity_type' => 'Exam',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Exam scheduled successfully.',
            'data' => $exam
        ], 201);
    }
}