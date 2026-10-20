<?php

namespace App\Services\Collaborators;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExamCollaboratorService
{
    public function __construct(private CollaborationAuditService $audit) {}

    public function responsibleExam(int $examId, int $userId): Exam
    {
        abort_unless(User::findOrFail($userId)->isActive(), 403, 'La cuenta no está activa.');
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        abort_unless((int) $exam->courseOffering?->teacher?->user_id === $userId, 403,
            'Solo el docente responsable puede gestionar los colaboradores de este examen.');

        return $exam;
    }

    public function candidateUsers(Exam $exam): Builder
    {
        // El perfil estudiantil determina la inscripción, incluso en usuarios con varios roles.
        return User::with(['teacher', 'student'])->where('status', 'ACTIVE')
            ->whereNotExists(function ($enrollment) use ($exam) {
                $enrollment->selectRaw('1')->from('enrollments')
                    ->join('students', 'students.id', '=', 'enrollments.student_id')
                    ->whereColumn('students.user_id', 'users.id')
                    ->where('enrollments.course_offering_id', $exam->course_offering_id)
                    ->where('enrollments.status', 'ACTIVE');
            });
    }

    public function isCandidate(Exam $exam, int $userId): bool
    {
        return $this->candidateUsers($exam)->whereKey($userId)->exists();
    }

    public function assignCollaborator(int $examId, int $userId, Request $request): ExamCollaborator
    {
        return $this->transition(function () use ($examId, $userId, $request) {
            // Serializar las asignaciones del mismo examen, incluso la primera.
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            $actorId = $this->authorizeTransition($exam, $request);
            if (! ExamCollaborator::examIsAvailable($exam)) {
                throw ValidationException::withMessages(['exam_id' => ['El examen ya finalizó o no está disponible.']]);
            }

            User::findOrFail($userId);
            if (! $this->isCandidate($exam, $userId)) {
                throw ValidationException::withMessages(['user_id' => ['El colaborador debe tener una cuenta activa y no tener una inscripción estudiantil activa en la oferta del examen.']]);
            }

            $existing = ExamCollaborator::where('exam_id', $examId)->where('user_id', $userId)->lockForUpdate()->first();
            if ($existing && $existing->status === 'ACTIVE') {
                throw ValidationException::withMessages(['user_id' => ['El usuario ya está asignado como colaborador activo para este examen.']]);
            }

            $values = [
                'assigned_by' => $actorId,
                'access_code_hash' => hash('sha256', Str::random(32)),
                'status' => 'ACTIVE',
                'assigned_at' => now(),
                'revoked_at' => null,
            ];

            $before = $existing ? $this->audit->snapshot($existing) : null;
            if ($existing) {
                $existing->update($values);
            } else {
                $existing = ExamCollaborator::create($values + ['exam_id' => $examId, 'user_id' => $userId]);
            }
            $existing->refresh();
            $this->audit->open($request, $actorId, $existing, $before);

            return $existing->load(['user.teacher', 'user.student', 'assigner.teacher', 'assigner.student']);
        });
    }

    public function getExamCollaborators(int $examId)
    {
        return ExamCollaborator::with(['user.teacher', 'user.student', 'assigner.teacher', 'assigner.student'])
            ->where('exam_id', $examId)->where('status', 'ACTIVE')->orderBy('id')->get();
    }

    public function revokeCollaborator(int $examId, int $userId, Request $request): void
    {
        $this->responsibleExam($examId, $request->user()->id);
        // Bind this request to the observed period before waiting for the exam lock.
        // The existing rotating hash is compared only in memory, never audited or logged.
        $observed = ExamCollaborator::where('exam_id', $examId)
            ->where('user_id', $userId)->where('status', 'ACTIVE')->firstOrFail();
        $this->transition(function () use ($examId, $userId, $request, $observed) {
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            $actorId = $this->authorizeTransition($exam, $request);
            $collaborator = ExamCollaborator::where('exam_id', $examId)->where('user_id', $userId)
                ->where('status', 'ACTIVE')->lockForUpdate()->firstOrFail();
            abort_unless($collaborator->id === $observed->id
                && hash_equals($observed->access_code_hash, $collaborator->access_code_hash), 404);

            $before = $this->audit->snapshot($collaborator);
            $openingId = $this->audit->openingId($collaborator);
            $collaborator->update(['status' => 'REVOKED', 'revoked_at' => now()]);
            $this->audit->close($request, $actorId, $collaborator->refresh(), $before, $openingId);
        });
    }

    private function authorizeTransition(Exam $exam, Request $request): int
    {
        $actor = User::findOrFail($request->user()->id);
        abort_unless($actor->isActive() && $actor->hasRole(RoleName::DOCENTE)
            && (int) $exam->courseOffering?->teacher?->user_id === $actor->id, 403);

        return (int) $actor->id;
    }

    private function transition(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface|HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // DB::transaction has rolled back before any error response is produced.
            Log::error('Exam collaborator transition failed.', ['exception_type' => $exception::class]);
            throw new HttpResponseException(response()->json([
                'message' => 'No se pudo confirmar la operación de colaboración. No se aplicaron cambios; la autorización conserva su estado anterior. Intenta nuevamente.',
                'code' => 'INTERNAL_ERROR',
            ], 500));
        }
    }

    public function getUserCollaborations(int $userId)
    {
        return ExamCollaborator::with(['exam.courseOffering.subject', 'exam.room'])
            ->where('user_id', $userId)->where('status', 'ACTIVE')->orderBy('id')->get()
            ->filter(fn ($collaboration) => ExamCollaborator::examIsAvailable($collaboration->exam) && $this->isCandidate($collaboration->exam, $userId))
            ->values();
    }
}
