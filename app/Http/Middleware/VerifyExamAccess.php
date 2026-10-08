<?php

namespace App\Http\Middleware;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Services\Collaborators\ExamCollaboratorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyExamAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (! $user->isActive()) {
            return response()->json(['message' => 'La cuenta no está activa.'], 403);
        }

        $examId = $request->route('exam') ?? $request->route('exam_id') ?? $request->input('exam_id');
        if (! $examId) {
            return response()->json(['message' => 'Examen no especificado'], 400);
        }
        $exam = $examId instanceof Exam ? $examId->load('courseOffering.teacher')
            : Exam::with('courseOffering.teacher')->find($examId);
        if (! $exam) {
            return response()->json(['message' => 'Examen no encontrado'], 404);
        }

        if ((int) $exam->courseOffering?->teacher?->user_id === $user->id) {
            return $next($request);
        }

        if (ExamCollaborator::examIsAvailable($exam)
            && app(ExamCollaboratorService::class)->isCandidate($exam, $user->id)
            && ExamCollaborator::where('exam_id', $exam->id)->where('user_id', $user->id)
                ->where('status', 'ACTIVE')->exists()) {
            return $next($request);
        }

        return response()->json(['message' => 'No tiene autorización vigente para verificar este examen'], 403);
    }
}
