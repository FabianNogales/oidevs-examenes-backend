<?php

namespace App\Services\Collaborators;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Student;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamCollaboratorService
{
    /**
     * Designar a un estudiante como colaborador temporal para un examen (HU15).
     */
    public function assignCollaborator(int $examId, int $studentId, int $assignedByUserId): ExamCollaborator
    {
        $exam = Exam::findOrFail($examId);
        $student = Student::findOrFail($studentId);

        // Validar que el estudiante no esté ya asignado activamente
        $existing = ExamCollaborator::where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->where('status', 'ACTIVE')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'student_id' => ['El estudiante ya está designado como colaborador activo para este examen.']
            ]);
        }

        // Generar un hash de código de acceso temporal
        $accessCode = Str::random(32);

        return ExamCollaborator::create([
            'exam_id' => $examId,
            'student_id' => $studentId,
            'assigned_by' => $assignedByUserId,
            'access_code_hash' => hash('sha256', $accessCode),
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);
    }

    /**
     * Listar todos los colaboradores de un examen (HU15).
     */
    public function getExamCollaborators(int $examId)
    {
        return ExamCollaborator::with(['student.user', 'assigner'])
            ->where('exam_id', $examId)
            ->get();
    }

    /**
     * Revocar la autorización de un colaborador (HU15).
     */
    public function revokeCollaborator(int $examId, int $collaboratorId): ExamCollaborator
    {
        $collaborator = ExamCollaborator::where('exam_id', $examId)
            ->where('id', $collaboratorId)
            ->firstOrFail();

        $collaborator->update([
            'status' => 'REVOKED',
            'revoked_at' => now(),
        ]);

        return $collaborator;
    }

    /**
     * Listar los exámenes donde el estudiante autenticado es colaborador activo (HU16).
     */
    public function getStudentCollaborations(int $userId)
    {
        $student = Student::where('user_id', $userId)->firstOrFail();

        return ExamCollaborator::with(['exam.courseOffering.subject', 'exam.room'])
            ->where('student_id', $student->id)
            ->where('status', 'ACTIVE')
            ->get();
    }
}