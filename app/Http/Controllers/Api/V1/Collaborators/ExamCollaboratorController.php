<?php

namespace App\Http\Controllers\Api\V1\Collaborators;

use App\Http\Controllers\Controller;
use App\Models\ExamCollaborator;
use App\Models\User;
use App\Services\Collaborators\ExamCollaboratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamCollaboratorController extends Controller
{
    public function __construct(protected ExamCollaboratorService $collaboratorService) {}

    private function assignmentData(ExamCollaborator $collaborator): array
    {
        return [
            'id' => $collaborator->id,
            'exam_id' => $collaborator->exam_id,
            'user_id' => $collaborator->user_id,
            'assigned_at' => $collaborator->assigned_at->toISOString(),
            'assigned_by' => $collaborator->assigned_by,
            'assigned_by_name' => $collaborator->assigner->display_name,
        ];
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'identity_number' => $user->teacher?->identity_number ?? $user->student?->identity_number,
        ];
    }

    public function index(Request $request, int $examId): JsonResponse
    {
        $exam = $this->collaboratorService->responsibleExam($examId, $request->user()->id);
        $collaborators = ExamCollaborator::examIsAvailable($exam)
            ? $this->collaboratorService->getExamCollaborators($examId)
            : collect();

        return response()->json(['data' => $collaborators->map(function ($collaborator) {
            $user = $this->userData($collaborator->user);
            unset($user['id']);

            return $this->assignmentData($collaborator) + $user;
        })->values()]);
    }

    public function store(Request $request, int $examId): JsonResponse
    {
        $this->collaboratorService->responsibleExam($examId, $request->user()->id);
        $validated = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $collaborator = $this->collaboratorService->assignCollaborator(
            $examId, $validated['user_id'], $request->user()->id
        );

        return response()->json([
            'message' => 'Colaborador asignado exitosamente.',
            'data' => $this->assignmentData($collaborator),
        ], 201);
    }

    public function destroy(Request $request, int $examId, int $userId): JsonResponse
    {
        $this->collaboratorService->responsibleExam($examId, $request->user()->id);
        $this->collaboratorService->revokeCollaborator($examId, $userId);

        return response()->json(['message' => 'Autorización de colaborador revocada exitosamente.']);
    }

    public function myCollaborations(Request $request): JsonResponse
    {
        abort_unless($request->user()->isActive(), 403, 'La cuenta no está activa.');
        $collaborations = $this->collaboratorService->getUserCollaborations($request->user()->id);

        return response()->json(['data' => $collaborations->map(fn ($collaboration) => [
            'exam_id' => $collaboration->exam_id,
            'exam_name' => $collaboration->exam->name,
            'subject_name' => $collaboration->exam->courseOffering->subject->name,
            'exam_date' => $collaboration->exam->exam_date,
            'start_time' => $collaboration->exam->start_time,
            'duration_minutes' => $collaboration->exam->duration_minutes,
            'room' => $collaboration->exam->room?->name,
        ])]);
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()->isActive(), 403, 'La cuenta no está activa.');
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'exam_id' => 'required|integer|exists:exams,id',
        ]);
        $exam = $this->collaboratorService->responsibleExam($validated['exam_id'], $request->user()->id);
        $search = trim($validated['search'] ?? '');
        $query = $this->collaboratorService->candidateUsers($exam);

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->whereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($search).'%']);
                foreach (['teacher', 'student'] as $relation) {
                    $query->orWhereHas($relation, function ($profile) use ($search) {
                        $profile->where('first_names', 'like', '%'.$search.'%')
                            ->orWhere('last_names', 'like', '%'.$search.'%')
                            ->orWhereRaw("LOWER(first_names || ' ' || last_names) LIKE ?", ['%'.mb_strtolower($search).'%'])
                            ->orWhere('identity_number', 'like', '%'.$search.'%');
                    });
                }
            });
        }

        return response()->json(['data' => $query->orderBy('id')->get()->map(fn ($user) => $this->userData($user))]);
    }
}
