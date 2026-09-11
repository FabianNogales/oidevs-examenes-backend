<?php

namespace App\Http\Controllers\Api\V1\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\StoreExamRequest;
use App\Models\CourseOffering;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;

class ExamSchedulingController extends Controller
{
    public function store(StoreExamRequest $request, int $courseOfferingId): JsonResponse
    {
        $courseOffering = CourseOffering::findOrFail($courseOfferingId);

        // Prevención IDOR: Validar que el usuario autenticado sea el dueño de la materia
        if ($courseOffering->teacher_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $exam = Exam::create([
            'course_offering_id' => $courseOffering->id,
            'room_id' => $request->validated('room_id'),
            'name' => $request->validated('name'),
            'exam_date' => $request->validated('exam_date'),
            'start_time' => $request->validated('start_time'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'rules' => $request->validated('rules'),
            'status' => 'SCHEDULED', 
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Exam scheduled successfully.',
            'data' => $exam
        ], 201);
    }
}