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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ExamUpdateService
{
    private const FIELDS = ['name', 'exam_date', 'start_time', 'duration_minutes', 'room_id', 'evaluation_type', 'rules'];

    public function __construct(
        private readonly ExamManagementService $exams,
        private readonly ExamManagementPolicy $policy,
        private readonly AcademicTermIntegrity $integrity,
        private readonly CourseOfferingAccess $access,
        private readonly RoomAvailabilityService $availability,
        private readonly AuditLogService $audit,
        private readonly ExamRevision $revision,
        private readonly ExamSchedulePolicy $schedulePolicy,
        private readonly ExamRoomAssignmentService $roomAssignments
    ) {}

    public function update(string $id, array $data, Request $request): Exam
    {
        $authorized = $this->exams->find($request->user(), $id);

        try {
            return $this->integrity->transaction(function () use ($authorized, $data, $request) {
                $exam = Exam::whereKey($authorized->id)->lockForUpdate()->firstOrFail();
                $this->revision->assertMatches($exam, $data['expected_updated_at']);

                return $this->access->run(
                    $exam->course_offering_id,
                    $request->user()->id,
                    true,
                    function (CourseOffering $offering) use ($exam, $data, $request) {
                        $actor = User::findOrFail($request->user()->id);
                        $exam->setRelation('courseOffering', $offering->load(['teacher', 'subject', 'academicTerm']));

                        $permissions = $this->policy->permissions($exam, $actor);
                        if (! $permissions['can_edit']) {
                            throw new HttpResponseException(
                                response()->json([
                                    'message' => $permissions['edit_block_reason'],
                                    'code' => 'EXAM_EDIT_NOT_ALLOWED',
                                ], 409)
                            );
                        }

                        $this->schedulePolicy->assertWithinAcademicTerm(
                            $offering->academicTerm,
                            (string) $data['exam_date']
                        );

                        $hasSharedColumn = Schema::hasColumn('exams', 'is_shared_room');
                        $hasPartialColumn = Schema::hasColumn('exams', 'partial_number');

                        // Shared rooms are disabled; ignore is_shared_room and shared_consent.
                        $allowSharing = false;

                        $primaryRoomId = (int) $data['room_id'];
                        $additionalRoomIds = $this->roomAssignments->normalizeAdditionalRoomIds(
                            $primaryRoomId,
                            $data['additional_room_ids'] ?? $request->input('additional_room_ids', [])
                        );

                        $targetRoomIds = array_merge([$primaryRoomId], $additionalRoomIds);
                        $existingPivotRoomIds = $this->roomAssignments->assignedRoomIds($exam->id, (int) $exam->room_id);

                        // Bloquea en orden numérico todas las aulas involucradas para evitar interbloqueos
                        $roomIdsToLock = array_values(array_unique(array_merge(
                            [(int) $exam->room_id],
                            $existingPivotRoomIds,
                            $targetRoomIds
                        )));
                        sort($roomIdsToLock, SORT_NUMERIC);
                        Room::whereIn('id', $roomIdsToLock)->orderBy('id')->lockForUpdate()->get();

                        [$start] = $this->availability->interval(
                            $data['exam_date'],
                            $data['start_time'],
                            (int) $data['duration_minutes']
                        );

                        if ($start->lessThanOrEqualTo(CarbonImmutable::now(ExamManagementPolicy::TIMEZONE))) {
                            throw ValidationException::withMessages([
                                'start_time' => ExamScheduleTime::FUTURE_START_ERROR,
                            ]);
                        }

                        foreach ($targetRoomIds as $roomIdToReserve) {
                            $this->availability->ensureReservable(
                                $roomIdToReserve,
                                $data['exam_date'],
                                $data['start_time'],
                                (int) $data['duration_minutes'],
                                $exam->id,
                                $allowSharing
                            );
                        }

                        $evaluationType = (string) $data['evaluation_type'];
                        $partialNumber = $this->schedulePolicy->resolvePartialNumber(
                            $evaluationType,
                            $data['partial_number'] ?? $request->input('partial_number'),
                            (string) $data['name']
                        );

                        $old = $this->snapshot($exam, $existingPivotRoomIds);
                        $exam->fill(array_intersect_key($data, array_flip(self::FIELDS)));

                        $extraColumnsChanged = false;
                        if ($hasSharedColumn && (bool) ($exam->is_shared_room ?? false) !== false) {
                            $extraColumnsChanged = true;
                        }
                        if ($hasPartialColumn && ($exam->partial_number ?? null) !== $partialNumber) {
                            $extraColumnsChanged = true;
                        }

                        $sortedExistingRooms = $existingPivotRoomIds;
                        $sortedTargetRooms = $targetRoomIds;
                        sort($sortedExistingRooms, SORT_NUMERIC);
                        sort($sortedTargetRooms, SORT_NUMERIC);
                        $pivotRoomsChanged = $sortedExistingRooms !== $sortedTargetRooms;

                        if (! $exam->isDirty(self::FIELDS) && ! $extraColumnsChanged && ! $pivotRoomsChanged) {
                            return $exam->load('room');
                        }

                        $rescheduled = $exam->isDirty(['exam_date', 'start_time', 'duration_minutes']);
                        $this->revision->saveNext($exam);

                        if ($hasSharedColumn || $hasPartialColumn) {
                            $extraUpdates = [];
                            if ($hasSharedColumn) {
                                $extraUpdates['is_shared_room'] = false;
                            }
                            if ($hasPartialColumn) {
                                $extraUpdates['partial_number'] = $partialNumber;
                            }
                            DB::table('exams')->where('id', $exam->id)->update($extraUpdates);
                        }

                        $this->roomAssignments->syncExamRooms($exam->id, $targetRoomIds, replaceExisting: true);

                        $revoked = $rescheduled
                            ? StudentQrToken::where('exam_id', $exam->id)
                                ->where('status', 'ACTIVE')
                                ->whereNull('revoked_at')
                                ->update([
                                    'status' => 'REVOKED',
                                    'revoked_at' => now(),
                                    'updated_at' => now(),
                                ])
                            : 0;

                        $exam->refresh();

                        $this->audit->log(
                            $request,
                            $actor->id,
                            'EXAM_UPDATED',
                            'Exam',
                            $exam->id,
                            $old,
                            $this->snapshot($exam, $targetRoomIds) + ['revoked_qr_tokens' => $revoked]
                        );

                        return $exam->load([
                            'room',
                            'courseOffering.subject',
                            'courseOffering.academicTerm',
                            'courseOffering.teacher',
                        ]);
                    }
                );
            });
        } catch (HttpResponseException|ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpResponseException(
                response()->json([
                    'message' => 'No se pudo editar el examen. Recarga su información antes de reintentar.',
                    'code' => 'INTERNAL_ERROR',
                ], 500)
            );
        }
    }

    private function snapshot(Exam $exam, array $assignedRoomIds = []): array
    {
        $base = $exam->only([...self::FIELDS, 'course_offering_id', 'created_by', 'status']);

        if (Schema::hasColumn('exams', 'partial_number')) {
            $base['partial_number'] = $exam->partial_number ?? null;
        }
        if (Schema::hasColumn('exams', 'is_shared_room')) {
            $base['is_shared_room'] = (bool) ($exam->is_shared_room ?? false);
        }
        if (! empty($assignedRoomIds)) {
            $base['assigned_room_ids'] = array_values($assignedRoomIds);
        }

        return $base + ['updated_at' => $exam->updated_at?->toISOString()];
    }
}
