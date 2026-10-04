<?php

namespace App\Http\Controllers\Api\V1\Collaborators;

use App\Http\Controllers\Controller;
use App\Services\Collaborators\ExamCollaboratorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ExamCollaboratorController extends Controller
{
    protected ExamCollaboratorService $collaboratorService;

    public function __construct(ExamCollaboratorService $collaboratorService)
    {
        $this->collaboratorService = $collaboratorService;
    }

    /**
     * Listar colaboradores asignados a un examen.
     */
    public function index(int $examId): JsonResponse
    {
        $collaborators = $this->collaboratorService->getExamCollaborators($examId);

        return response()->json([
            'data' => $collaborators
        ], Response::HTTP_OK);
    }

    /**
     * Designar un nuevo colaborador para un examen (HU15).
     */
    public function store(Request $request, int $examId): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
        ]);

        $collaborator = $this->collaboratorService->assignCollaborator(
            $examId,
            $request->input('student_id'),
            $request->user()->id
        );

        return response()->json([
            'message' => 'Colaborador designado exitosamente',
            'data' => $collaborator
        ], Response::HTTP_CREATED);
    }

    /**
     * Revocar la designación de un colaborador (HU15).
     */
    public function destroy(int $examId, int $collaboratorId): JsonResponse
    {
        $collaborator = $this->collaboratorService->revokeCollaborator($examId, $collaboratorId);

        return response()->json([
            'message' => 'Autorización de colaborador revocada exitosamente',
            'data' => $collaborator
        ], Response::HTTP_OK);
    }

    /**
     * Consultar exámenes en los que el estudiante autenticado es colaborador (HU16).
     */
    public function myCollaborations(Request $request): JsonResponse
    {
        $collaborations = $this->collaboratorService->getStudentCollaborations($request->user()->id);

        return response()->json([
            'data' => $collaborations
        ], Response::HTTP_OK);
    }
}