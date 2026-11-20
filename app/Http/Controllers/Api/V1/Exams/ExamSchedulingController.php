<?php

namespace App\Http\Controllers\Api\V1\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\StoreExamRequest;
use App\Models\CourseOffering;
use App\Services\Exams\ExamSchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExamSchedulingController extends Controller
{
    public function __construct(
        private readonly ExamSchedulingService $schedulingService
    ) {}

    public function store(StoreExamRequest $request, int $courseOfferingId): JsonResponse
    {
        $courseOffering = CourseOffering::with('academicTerm')->findOrFail($courseOfferingId);
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();

        // Prevención IDOR actualizada
        if (! $teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $exam = $this->schedulingService->schedule($courseOffering, $request->validated(), $request);

        return response()->json([
            'message' => 'Exam scheduled successfully.',
            'data' => $exam,
        ], 201);
    }
}
