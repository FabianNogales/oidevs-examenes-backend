<?php

namespace App\Services\Rooms;

use App\Enums\EvaluationType;
use App\Models\CourseOffering;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoomCatalogContextService
{
    private const RESERVING_STATUSES = ['ACTIVE', 'SCHEDULED', 'IN_PROGRESS'];

    private const CAPACITY_SAFETY_MARGIN = 10;

    public function __construct(
        private readonly RoomAvailabilityService $availability
    ) {}

    /**
     * Construye el mapa de horarios ocupados por aula usando bloques completos de 90 min.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function schedulesByRoomId(?int $excludeExamId = null): array
    {
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
            $schedulesByRoomId[$exam->room_id][$exam->id] = $this->formatSlot($exam);
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

                $schedulesByRoomId[$pivot->room_id][$exam->id] = $this->formatSlot($exam);
            }
        }

        return $schedulesByRoomId;
    }

    /**
     * Metadatos de la oferta: siguiente parcial, capacidad recomendada y periodo académico.
     *
     * @return array<string, mixed>
     */
    public function offeringMeta(int $offeringId): array
    {
        $hasPartialNumberColumn = Schema::hasColumn('exams', 'partial_number');
        $courseOffering = CourseOffering::with('academicTerm')->find($offeringId);
        $academicTerm = $courseOffering?->academicTerm;

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
    private function formatSlot(object $exam): array
    {
        [, $end] = $this->availability->interval($exam->exam_date, $exam->start_time, (int) $exam->duration_minutes);

        return [
            'exam_id' => (int) $exam->id,
            'exam_name' => (string) $exam->name,
            'date' => (string) $exam->exam_date,
            'start' => substr((string) $exam->start_time, 0, 5),
            'end' => $end->format('H:i'),
            'is_shared' => false,
        ];
    }
}
