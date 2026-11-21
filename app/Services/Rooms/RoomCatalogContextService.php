<?php

namespace App\Services\Rooms;

use App\Enums\EvaluationType;
use App\Enums\RoleName;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\CourseOfferings\CourseOfferingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoomCatalogContextService
{
    private const RESERVING_STATUSES = ['ACTIVE', 'SCHEDULED', 'IN_PROGRESS'];

    private const CAPACITY_SAFETY_MARGIN = 10;

    public function __construct(
        private readonly RoomAvailabilityService $availability,
        private readonly CourseOfferingAccess $offeringAccess
    ) {}

    /**
     * Construye el mapa de horarios ocupados por aula usando bloques completos de 90 min.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function schedulesByRoomId(User $actor, ?int $excludeExamId = null): array
    {
        abort_unless($actor->isActive() && ! $actor->must_change_password, 403);
        $administrator = $actor->hasRole(RoleName::ADMINISTRADOR);
        if (! $administrator && ! $actor->hasRole(RoleName::DOCENTE)) {
            return [];
        }
        $ownOfferingIds = $administrator ? [] : CourseOffering::whereHas('teacher', fn ($query) => $query->where('user_id', $actor->id))
            ->pluck('id')->flip()->all();
        $activeExamsQuery = DB::table('exams')
            ->whereIn('status', self::RESERVING_STATUSES)
            ->orderBy('exam_date')
            ->orderBy('start_time');

        if ($excludeExamId !== null) {
            $activeExamsQuery->where('id', '!=', $excludeExamId);
        }

        $activeExams = $activeExamsQuery->get();
        $examsById = $activeExams->keyBy('id');

        $schedulesByRoomId = [];
        foreach ($activeExams as $exam) {
            $schedulesByRoomId[$exam->room_id][$exam->id] = $this->formatSlot($exam,
                $administrator || isset($ownOfferingIds[$exam->course_offering_id]));
        }

        if (Schema::hasTable('exam_rooms') && $examsById->isNotEmpty()) {
            $pivotRows = DB::table('exam_rooms')
                ->whereIn('exam_id', $examsById->keys())
                ->get();

            foreach ($pivotRows as $pivot) {
                $exam = $examsById->get($pivot->exam_id);
                if (! $exam) {
                    continue;
                }

                $schedulesByRoomId[$pivot->room_id][$exam->id] = $this->formatSlot($exam,
                    $administrator || isset($ownOfferingIds[$exam->course_offering_id]));
            }
        }

        return $schedulesByRoomId;
    }

    /**
     * Metadatos de la oferta: siguiente parcial, capacidad recomendada y periodo académico.
     *
     * @return array<string, mixed>
     */
    public function offeringMeta(User $actor, int $offeringId): array
    {
        abort_unless($actor->isActive() && ! $actor->must_change_password, 403);
        if ($actor->hasRole(RoleName::ADMINISTRADOR)) {
            return $this->metadata(CourseOffering::with('academicTerm')->findOrFail($offeringId));
        }
        abort_unless($actor->hasRole(RoleName::DOCENTE), 403);

        return $this->offeringAccess->run($offeringId, $actor->id, false,
            fn (CourseOffering $offering) => $this->metadata($offering));
    }

    private function metadata(CourseOffering $courseOffering): array
    {
        $offeringId = $courseOffering->id;
        $hasPartialNumberColumn = Schema::hasColumn('exams', 'partial_number');
        $academicTerm = $courseOffering->academicTerm;

        $enrolledCount = DB::table('enrollments')
            ->where('course_offering_id', $offeringId)
            ->where('status', 'ACTIVE')
            ->count();

        $partialExams = DB::table('exams')
            ->where('course_offering_id', $offeringId)
            ->where('evaluation_type', EvaluationType::PARTIAL->value)
            ->whereNotIn('status', ['CANCELLED'])
            ->get();

        $maxPartialNumber = 0;
        foreach ($partialExams as $partialExam) {
            if ($hasPartialNumberColumn && ! empty($partialExam->partial_number)) {
                $maxPartialNumber = max($maxPartialNumber, (int) $partialExam->partial_number);
            } elseif (preg_match('/([1-6])/', (string) $partialExam->name, $matches)) {
                $maxPartialNumber = max($maxPartialNumber, (int) $matches[1]);
            } else {
                $maxPartialNumber++;
            }
        }

        $nextPartialNumber = min(6, max(1, $maxPartialNumber + 1));

        $latestExamId = DB::table('exams')
            ->where('course_offering_id', $offeringId)
            ->whereNotIn('status', ['CANCELLED'])
            ->orderByDesc('id')
            ->value('id');

        $eligibleCount = $latestExamId
            ? DB::table('exam_eligibilities')
                ->where('exam_id', $latestExamId)
                ->where('status', 'ELIGIBLE')
                ->count()
            : $enrolledCount;

        $baseStudents = $eligibleCount > 0 ? $eligibleCount : $enrolledCount;

        return [
            'next_partial_number' => $nextPartialNumber,
            'last_partial_number' => $maxPartialNumber,
            'enrolled_students_count' => $enrolledCount,
            'eligible_students_count' => $baseStudents,
            'safety_margin' => self::CAPACITY_SAFETY_MARGIN,
            'recommended_capacity' => $baseStudents > 0 ? $baseStudents + self::CAPACITY_SAFETY_MARGIN : 0,
            'academic_term' => $academicTerm ? [
                'id' => (int) $academicTerm->id,
                'name' => (string) $academicTerm->name,
                'start_date' => substr((string) $academicTerm->start_date, 0, 10),
                'end_date' => substr((string) $academicTerm->end_date, 0, 10),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSlot(object $exam, bool $canInspect): array
    {
        [, $end] = $this->availability->interval($exam->exam_date, $exam->start_time, (int) $exam->duration_minutes);

        return [
            // Other teachers need occupancy to plan, never another exam's identity.
            'exam_id' => $canInspect ? (int) $exam->id : null,
            'exam_name' => $canInspect ? (string) $exam->name : 'Reservado',
            'date' => (string) $exam->exam_date,
            'start' => substr((string) $exam->start_time, 0, 5),
            'end' => $end->format('H:i'),
            'is_shared' => false,
        ];
    }
}
