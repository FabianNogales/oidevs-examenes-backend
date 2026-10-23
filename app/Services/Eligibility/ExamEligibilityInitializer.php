<?php

namespace App\Services\Eligibility;

use App\Models\Exam;
use App\Services\Entries\ExamEntryAvailability;
use Illuminate\Support\Facades\DB;

class ExamEligibilityInitializer
{
    public function lockExamsBeforeEnrollment(int $courseOfferingId): void
    {
        // Called under the existing academic-term transaction lock, before offering/user locks.
        $ids = $this->availableExams($courseOfferingId)->modelKeys();
        Exam::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function forExam(Exam $exam, int $actorId): void
    {
        $this->createMissing($exam, $this->activeStudentIds($exam->course_offering_id), $actorId);
    }

    public function forNewEnrollments(int $courseOfferingId, array $studentIds, int $actorId): void
    {
        $activeIds = $this->activeStudentIds($courseOfferingId, $studentIds);
        if ($activeIds === []) {
            return;
        }
        foreach ($this->availableExams($courseOfferingId) as $exam) {
            $this->createMissing($exam, $activeIds, $actorId);
        }
    }

    private function availableExams(int $courseOfferingId)
    {
        return Exam::where('course_offering_id', $courseOfferingId)->whereIn('status', ExamEntryAvailability::STATES)
            ->orderBy('id')->get()->filter(fn (Exam $exam) => ExamEntryAvailability::preparationReason($exam) === null);
    }

    private function activeStudentIds(int $courseOfferingId, ?array $only = null): array
    {
        $query = DB::table('enrollments')->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.course_offering_id', $courseOfferingId)
            ->where('enrollments.status', 'ACTIVE')->where('students.status', 'ACTIVE');
        if ($only !== null) {
            $query->whereIn('students.id', $only);
        }

        return $query->distinct()->pluck('students.id')->all();
    }

    private function createMissing(Exam $exam, array $studentIds, int $actorId): void
    {
        // Never update or contend on an existing manual decision just to initialize missing rows.
        $existing = DB::table('exam_eligibilities')->where('exam_id', $exam->id)
            ->whereIn('student_id', $studentIds)->pluck('student_id')->all();
        $missing = array_values(array_diff($studentIds, $existing));
        $now = now();
        foreach (array_chunk($missing, 500) as $chunk) {
            DB::table('exam_eligibilities')->insertOrIgnore(array_map(fn (int $studentId) => [
                'exam_id' => $exam->id, 'student_id' => $studentId, 'status' => 'ELIGIBLE', 'reason' => null,
                'evaluated_by' => $actorId, 'evaluated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }
}
