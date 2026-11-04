<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\SpecialCase;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentRegistrationService
{
    public function __construct(private readonly AuditLogService $audit, private readonly IncidentAccessService $access) {}

    public function register(int $examId, array $data, Request $request): SpecialCase
    {
        return DB::transaction(function () use ($examId, $data, $request) {
            // Match the exam-first lock order used by entry control and collaborator changes.
            $exam = Exam::query()->lockForUpdate()->findOrFail($examId);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $this->access->authorize($exam, $actor, lockAssignment: true);
            if (isset($data['student_id'])) {
                $related = DB::table('enrollments')->where('course_offering_id', $exam->course_offering_id)
                    ->where('student_id', $data['student_id'])->exists()
                    || DB::table('exam_eligibilities')->where('exam_id', $exam->id)->where('student_id', $data['student_id'])->exists()
                    || DB::table('exam_entries')->where('exam_id', $exam->id)->where('student_id', $data['student_id'])->exists();
                if (! $related) {
                    throw ValidationException::withMessages(['student_id' => 'El estudiante no es válido para este examen.']);
                }
            }

            $incident = SpecialCase::query()->create([
                'exam_id' => $exam->id,
                'student_id' => $data['student_id'] ?? null,
                'type' => $data['type'],
                'description' => $data['description'],
                'reported_by' => $actor->id,
                // A report is pending review, never a confirmed disciplinary infraction.
                'status' => 'PENDING',
            ]);

            $this->audit->log($request, $actor->id, 'INCIDENT_REPORTED', 'SpecialCase', $incident->id,
                newValues: [
                    'exam_id' => $incident->exam_id, 'student_id' => $incident->student_id,
                    'type' => $incident->type->value, 'description' => $incident->description,
                    'origin' => 'MANUAL', 'status' => $incident->status,
                ]);

            return $incident->load(['student', 'reporter.teacher', 'reporter.student']);
        });
    }
}
