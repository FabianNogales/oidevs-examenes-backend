<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eligibility\UpdateEligibilityRequest;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamEligibilityController extends Controller
{
    /**
     * Listar estudiantes y sus estados de habilitación para un examen.
     */
    public function index(Request $request, int $examId): JsonResponse
    {
        $teacher = Teacher::where('user_id', $request->user()->id)->first();


        if (!$teacher) {
            return response()->json(['message' => 'Forbidden - User is not a teacher.'], 403);
        }

        $exam = Exam::with('courseOffering')->findOrFail($examId);

        if ($exam->courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this exam.'], 403);
        }

        $query = ExamEligibility::query()
            ->with(['student.user', 'evaluator'])
            ->where('exam_id', $examId);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = strtolower(trim($request->query('search')));
            $query->whereHas('student', function ($q) use ($search) {
                $q->whereRaw('LOWER(first_names) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(last_names) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(sis_code) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(identity_number) LIKE ?', ["%{$search}%"]);
            });
        }

        $eligibilities = $query->get()->map(function ($eligibility) {
            return [
                'id' => $eligibility->id,
                'student_id' => $eligibility->student_id,
                'sis_code' => $eligibility->student->sis_code ?? null,
                'identity_number' => $eligibility->student->identity_number ?? null,
                'first_names' => $eligibility->student->first_names ?? null,
                'last_names' => $eligibility->student->last_names ?? null,
                'email' => $eligibility->student->user->email ?? null,
                'status' => $eligibility->status,
                'reason' => $eligibility->reason,
                'evaluated_by' => $eligibility->evaluator?->email,
                'evaluated_at' => $eligibility->evaluated_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $eligibilities]);
    }

    /**
     * Actualizar estado de habilitación de un estudiante.
     */
    public function update(UpdateEligibilityRequest $request, int $examId, int $studentId): JsonResponse
    {
        $teacher = Teacher::where('user_id', $request->user()->id)->first();

        if (!$teacher) {
            return response()->json(['message' => 'Forbidden - User is not a teacher.'], 403);
        }

        $exam = Exam::with('courseOffering')->findOrFail($examId);

        if ($exam->courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this exam.'], 403);
        }

        $eligibility = ExamEligibility::where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->firstOrFail();

        $status = $request->validated('status');
        $reason = $status === 'INELIGIBLE' ? $request->validated('reason') : null;

        $eligibility->update([
            'status' => $status,
            'reason' => $reason,
            'evaluated_by' => $request->user()->id,
            'evaluated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Estado de habilitación actualizado correctamente.',
            'data' => [
                'id' => $eligibility->id,
                'exam_id' => $eligibility->exam_id,
                'student_id' => $eligibility->student_id,
                'status' => $eligibility->status,
                'reason' => $eligibility->reason,
                'evaluated_at' => $eligibility->evaluated_at->toIso8601String(),
            ],
        ]);
    }
}