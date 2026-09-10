<?php

namespace App\Http\Controllers\Api\V1\TeacherDashboard;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherDashboardController extends Controller
{
    public function getAssignedSubjects(Request $request): JsonResponse
    {
        // Prevención IDOR: Solo filtramos por el ID del usuario autenticado
        $subjects = CourseOffering::with(['subject', 'academicTerm'])
            ->where('teacher_user_id', $request->user()->id)
            ->where('status', 'ACTIVE')
            ->get();

        return response()->json(['data' => $subjects], 200);
    }

    public function getUpcomingExams(Request $request): JsonResponse
    {
        $exams = Exam::with(['courseOffering.subject'])
            ->whereHas('courseOffering', function ($query) use ($request) {
                // Prevención IDOR en tablas relacionadas
                $query->where('teacher_user_id', $request->user()->id);
            })
            ->where('status', 'SCHEDULED')
            ->where('exam_date', '>=', now()->toDateString())
            ->orderBy('exam_date', 'asc')
            ->get();

        return response()->json(['data' => $exams], 200);
    }
}