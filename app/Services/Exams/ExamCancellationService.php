<?php

namespace App\Services\Exams;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Room;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use App\Services\Audit\AuditLogService;
use App\Services\CourseOfferings\CourseOfferingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class ExamCancellationService
{
    public function __construct(private readonly ExamManagementService $exams, private readonly ExamManagementPolicy $policy,
        private readonly AcademicTermIntegrity $integrity, private readonly CourseOfferingAccess $access, private readonly AuditLogService $audit) {}

    public function cancel(string $id, string $expectedUpdatedAt, Request $request): Exam
    {
        // Authorize the exact ID before entering any mutation path.
        $authorized = $this->exams->find($request->user(), $id);
        try {
            return $this->integrity->transaction(function () use ($authorized, $expectedUpdatedAt, $request) {
                // QR generation and entry registration also lock the exam first.
                $exam = Exam::whereKey($authorized->id)->lockForUpdate()->firstOrFail();
                if ($exam->status === 'CANCELLED') {
                    $this->conflict('EXAM_ALREADY_CANCELLED', 'El examen ya está cancelado.');
                }
                $expected = CarbonImmutable::parse($expectedUpdatedAt);
                if (! $exam->updated_at || ! $expected->equalTo($exam->updated_at)) {
                    $this->conflict('EXAM_VERSION_CONFLICT', 'El examen fue modificado. Recarga su información.');
                }

                return $this->access->run($exam->course_offering_id, $request->user()->id, true,
                    function (CourseOffering $offering) use ($exam, $request) {
                        $actor = User::findOrFail($request->user()->id);
                        $exam->setRelation('courseOffering', $offering->load(['teacher', 'subject', 'academicTerm']));
                        $permissions = $this->policy->permissions($exam, $actor);
                        if (! $permissions['can_cancel']) {
                            $this->conflict('EXAM_CANCELLATION_NOT_ALLOWED', $permissions['cancel_block_reason']);
                        }
                        // Coordinate logical release with the existing room reservation lock.
                        Room::whereKey($exam->room_id)->lockForUpdate()->first();
                        $old = $this->snapshot($exam);
                        $exam->status = 'CANCELLED';
                        $exam->save();
                        $revoked = StudentQrToken::where('exam_id', $exam->id)->where('status', 'ACTIVE')->whereNull('revoked_at')
                            ->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
                        $exam->refresh();
                        $this->audit->log($request, $actor->id, 'EXAM_CANCELLED', 'Exam', $exam->id,
                            $old, $this->snapshot($exam) + ['revoked_qr_tokens' => $revoked]);

                        return $exam->load(['room', 'courseOffering.subject', 'courseOffering.academicTerm', 'courseOffering.teacher']);
                    });
            });
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpResponseException(response()->json(['message' => 'No se pudo cancelar el examen. Recarga su información antes de reintentar.', 'code' => 'INTERNAL_ERROR'], 500));
        }
    }

    private function snapshot(Exam $exam): array
    {
        return $exam->only(['course_offering_id', 'room_id', 'created_by', 'name', 'exam_date', 'start_time', 'duration_minutes', 'evaluation_type', 'rules', 'status'])
            + ['updated_at' => $exam->updated_at?->toISOString()];
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('message', 'code'), 409));
    }
}
