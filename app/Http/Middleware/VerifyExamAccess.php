<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use Symfony\Component\HttpFoundation\Response;

class VerifyExamAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $examId = $request->route('exam') ?? $request->route('exam_id') ?? $request->input('exam_id');

        if (!$examId) {
            return response()->json(['message' => 'Examen no especificado'], Response::HTTP_BAD_REQUEST);
        }

        $exam = Exam::with('courseOffering')->find($examId);

        if (!$exam) {
            return response()->json(['message' => 'Examen no encontrado'], Response::HTTP_NOT_FOUND);
        }

        // 1. Acceso permitido si el usuario es el docente responsable del examen
        if ($exam->courseOffering && $exam->courseOffering->teacher_id === $user->id) {
            return $next($request);
        }

        // 2. Acceso permitido si el estudiante es un colaborador temporal activo
        if ($user->student) {
            $isCollaborator = ExamCollaborator::where('exam_id', $examId)
                ->where('student_id', $user->student->id)
                ->where('status', 'ACTIVE')
                ->exists();

            if ($isCollaborator) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'No tiene autorización para gestionar ni verificar este examen'
        ], Response::HTTP_FORBIDDEN);
    }
}
