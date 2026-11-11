<?php

namespace App\Services\Expulsions;

use App\Models\Expulsion;
use App\Models\Infraction;
use App\Models\Student;
use App\Models\DisciplineCase;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\ExamEntryAvailability;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExpulsionRegistrationService
{
    public function __construct(private readonly ExpulsionAccess $access, private readonly AuditLogService $audit) {}

    private function duplicate(): never
    {
        throw new HttpResponseException(response()->json(['code' => 'STUDENT_ALREADY_EXPELLED',
            'message' => 'El estudiante ya tiene una expulsión registrada en este examen.'], 409));
    }

    public function register(int $examId, array $data, Request $request): Expulsion
    {
        try {
            return DB::transaction(function () use ($examId, $data, $request) {
                $exam = $this->access->exam($examId, $request->user(), lock: true);
                if (Expulsion::where('exam_id', $examId)->where('student_id', $data['student_id'])->lockForUpdate()->exists()) {
                    $this->duplicate();
                }
                $reason = ExamEntryAvailability::reason($exam);
                if ($reason !== null) {
                    throw new HttpResponseException(response()->json(['code' => 'EXPULSION_EXAM_NOT_IN_PROGRESS',
                        'message' => 'Las expulsiones se registran durante el horario de un examen disponible.', 'reason' => $reason], 409));
                }
                Student::whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
                if (! $this->access->students($exam)->whereKey($data['student_id'])->exists()) {
                    throw ValidationException::withMessages(['student_id' => 'El estudiante no está relacionado con este examen.']);
                }
                if (isset($data['infraction_id'])) {
                    $infraction = Infraction::where('exam_id', $examId)->where('student_id', $data['student_id'])
                        ->whereKey($data['infraction_id'])->lockForUpdate()->first();
                    if (! $infraction) {
                        throw ValidationException::withMessages(['infraction_id' => 'La infracción debe corresponder al mismo estudiante y examen.']);
                    }
                }
                if (isset($data['discipline_case_id'])) {
                    $case = DisciplineCase::where('exam_id', $examId)->whereKey($data['discipline_case_id'])->lockForUpdate()->firstOrFail();
                    $studentId = $case->studentId();
                    if ($studentId !== (int) $data['student_id']) {
                        throw ValidationException::withMessages(['discipline_case_id' => 'El caso debe corresponder al mismo estudiante y examen.']);
                    }
                    if (isset($data['infraction_id']) && ($case->source_type !== 'INFRACTION' || $case->source_id !== (int) $data['infraction_id'])) {
                        throw ValidationException::withMessages(['discipline_case_id' => 'La infracción y el caso deben coincidir.']);
                    }
                }
                $expulsion = Expulsion::create(['exam_id' => $examId, 'student_id' => $data['student_id'],
                    'discipline_case_id' => $data['discipline_case_id'] ?? null,
                    'infraction_id' => $data['infraction_id'] ?? null, 'reason' => $data['reason'],
                    'expelled_by' => $request->user()->id, 'expelled_at' => now()]);
                $this->audit->log($request, $request->user()->id, 'STUDENT_EXPELLED', 'Expulsion', $expulsion->id,
                    newValues: $expulsion->only(['exam_id', 'student_id', 'infraction_id', 'discipline_case_id', 'reason', 'expelled_by'])
                        + ['expelled_at' => $expulsion->expelled_at->toISOString()]);

                return Expulsion::withContext()->findOrFail($expulsion->id);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'expulsions_exam_id_student_id_unique')
                || str_contains($exception->getMessage(), 'expulsions.exam_id, expulsions.student_id')) {
                $this->duplicate();
            }
            $this->internalError($exception);
        } catch (HttpExceptionInterface|HttpResponseException|ModelNotFoundException|ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->internalError($exception);
        }
    }

    private function internalError(\Throwable $exception): never
    {
        report($exception);
        throw new HttpResponseException(response()->json(['code' => 'INTERNAL_ERROR',
            'message' => 'No se pudo registrar la expulsión. Consulta su estado antes de reintentar.'], 500));
    }
}
