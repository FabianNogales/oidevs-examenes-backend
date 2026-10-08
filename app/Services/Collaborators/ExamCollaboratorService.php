<?php

namespace App\Services\Collaborators;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamCollaboratorService
{
    public function responsibleExam(int $examId, int $userId): Exam
    {
        abort_unless(User::findOrFail($userId)->isActive(), 403, 'La cuenta no está activa.');
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        abort_unless((int) $exam->courseOffering?->teacher?->user_id === $userId, 403,
            'Solo el docente responsable puede gestionar los colaboradores de este examen.');

        return $exam;
    }

    public function assignCollaborator(int $examId, int $userId, int $assignedByUserId): ExamCollaborator
    {
        return DB::transaction(function () use ($examId, $userId, $assignedByUserId) {
            // Serializar las asignaciones del mismo examen, incluso la primera.
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            if (! ExamCollaborator::examIsAvailable($exam)) {
                throw ValidationException::withMessages(['exam_id' => ['El examen ya finalizó o no está disponible.']]);
            }

            $user = User::findOrFail($userId);
            if (! $user->isActive()) {
                throw ValidationException::withMessages(['user_id' => ['El usuario debe tener una cuenta activa.']]);
            }

            $existing = ExamCollaborator::where('exam_id', $examId)->where('user_id', $userId)->first();
            if ($existing && $existing->status === 'ACTIVE') {
                throw ValidationException::withMessages(['user_id' => ['El usuario ya está asignado como colaborador activo para este examen.']]);
            }

            $values = [
                'assigned_by' => $assignedByUserId,
                'access_code_hash' => hash('sha256', Str::random(32)),
                'status' => 'ACTIVE',
                'assigned_at' => now(),
                'revoked_at' => null,
            ];

            if ($existing) {
                $existing->update($values);

                return $existing->load(['user.teacher', 'user.student', 'assigner.teacher', 'assigner.student']);
            }

            return ExamCollaborator::create($values + ['exam_id' => $examId, 'user_id' => $userId])
                ->load(['user.teacher', 'user.student', 'assigner.teacher', 'assigner.student']);
        });
    }

    public function getExamCollaborators(int $examId)
    {
        return ExamCollaborator::with(['user.teacher', 'user.student', 'assigner.teacher', 'assigner.student'])
            ->where('exam_id', $examId)->where('status', 'ACTIVE')->orderBy('id')->get();
    }

    public function revokeCollaborator(int $examId, int $userId): void
    {
        $collaborator = ExamCollaborator::where('exam_id', $examId)
            ->where('user_id', $userId)->where('status', 'ACTIVE')->firstOrFail();
        $collaborator->update(['status' => 'REVOKED', 'revoked_at' => now()]);
    }

    public function getUserCollaborations(int $userId)
    {
        return ExamCollaborator::with(['exam.courseOffering.subject', 'exam.room'])
            ->where('user_id', $userId)->where('status', 'ACTIVE')->orderBy('id')->get()
            ->filter(fn ($collaboration) => ExamCollaborator::examIsAvailable($collaboration->exam))
            ->values();
    }
}
