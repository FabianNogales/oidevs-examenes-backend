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
use App\Services\Rooms\RoomAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExamUpdateService
{
    private const FIELDS = ['name', 'exam_date', 'start_time', 'duration_minutes', 'room_id', 'evaluation_type', 'rules'];

    public function __construct(private readonly ExamManagementService $exams, private readonly ExamManagementPolicy $policy,
        private readonly AcademicTermIntegrity $integrity, private readonly CourseOfferingAccess $access,
        private readonly RoomAvailabilityService $availability, private readonly AuditLogService $audit, private readonly ExamRevision $revision) {}

    public function update(string $id, array $data, Request $request): Exam
    {
        $authorized = $this->exams->find($request->user(), $id);
        try {
            return $this->integrity->transaction(function () use ($authorized, $data, $request) {
                $exam = Exam::whereKey($authorized->id)->lockForUpdate()->firstOrFail();
                $this->revision->assertMatches($exam, $data['expected_updated_at']);

                return $this->access->run($exam->course_offering_id, $request->user()->id, true,
                    function (CourseOffering $offering) use ($exam, $data, $request) {
                        $actor = User::findOrFail($request->user()->id);
                        $exam->setRelation('courseOffering', $offering->load(['teacher', 'subject', 'academicTerm']));
                        $permissions = $this->policy->permissions($exam, $actor);
                        if (! $permissions['can_edit']) {
                            throw new HttpResponseException(response()->json(['message' => $permissions['edit_block_reason'], 'code' => 'EXAM_EDIT_NOT_ALLOWED'], 409));
                        }
                        $roomIds = array_values(array_unique([$exam->room_id, $data['room_id']]));
                        sort($roomIds, SORT_NUMERIC);
                        Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get();
                        [$start] = $this->availability->interval($data['exam_date'], $data['start_time'], $data['duration_minutes']);
                        if ($start->lessThanOrEqualTo(CarbonImmutable::now(ExamManagementPolicy::TIMEZONE))) {
                            throw ValidationException::withMessages(['start_time' => 'La fecha y hora del examen deben estar en el futuro.']);
                        }
                        $this->availability->ensureReservable($data['room_id'], $data['exam_date'], $data['start_time'], $data['duration_minutes'], $exam->id);
                        $old = $this->snapshot($exam);
                        $exam->fill(array_intersect_key($data, array_flip(self::FIELDS)));
                        if (! $exam->isDirty(self::FIELDS)) {
                            return $exam->load('room');
                        }
                        $rescheduled = $exam->isDirty(['exam_date', 'start_time', 'duration_minutes']);
                        $this->revision->saveNext($exam);
                        $revoked = $rescheduled ? StudentQrToken::where('exam_id', $exam->id)->where('status', 'ACTIVE')->whereNull('revoked_at')
                            ->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]) : 0;
                        $exam->refresh();
                        $this->audit->log($request, $actor->id, 'EXAM_UPDATED', 'Exam', $exam->id, $old,
                            $this->snapshot($exam) + ['revoked_qr_tokens' => $revoked]);

                        return $exam->load(['room', 'courseOffering.subject', 'courseOffering.academicTerm', 'courseOffering.teacher']);
                    });
            });
        } catch (HttpResponseException|ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpResponseException(response()->json(['message' => 'No se pudo editar el examen. Recarga su información antes de reintentar.', 'code' => 'INTERNAL_ERROR'], 500));
        }
    }

    private function snapshot(Exam $exam): array
    {
        return $exam->only([...self::FIELDS, 'course_offering_id', 'created_by', 'status']) + ['updated_at' => $exam->updated_at?->toISOString()];
    }
}
