<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Collaborators\CollaborationAuditService;
use App\Services\Collaborators\ExamCollaboratorService;
use App\Services\Exams\ExamManagementService;

class AdminProfileRecordsService
{
    public function records(string $type, int $id, array $filters): array
    {
        $profile = ($type === 'students' ? Student::query() : Teacher::query())->findOrFail($id);
        $user = User::findOrFail($profile->user_id);
        $section = $filters['section'];
        $summary = null;
        if ($section === 'roles') {
            $query = AuditLog::with('user.teacher', 'user.student')->where(function ($q) use ($user) {
                $q->where(fn ($q) => $q->where('entity_type', 'User')->where('entity_id', $user->id)
                    ->whereIn('action', ['USER_ROLE_ASSIGNED', 'USER_ROLE_REVOKED']))
                    ->orWhere(fn ($q) => $q->where('entity_type', 'ExamCollaborator')
                        ->whereIn('action', [CollaborationAuditService::ASSIGNED, CollaborationAuditService::REASSIGNED, CollaborationAuditService::REVOKED])
                        ->whereJsonContains('new_values->collaborator_user_id', (int) $user->id));
            });
            if ($search = trim($filters['search'] ?? '')) {
                $query->where('action', 'like', '%'.$search.'%');
            }
            $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
            $exams = Exam::whereIn('id', $page->getCollection()->map(fn ($event) => $event->new_values['exam_id'] ?? null)->filter())->pluck('name', 'id');
            $page->through(fn ($event) => [
                'id' => $event->id, 'role' => str_starts_with($event->action, 'EXAM_COLLABORATOR_')
                    ? 'COLLABORATOR' : ($event->new_values['role'] ?? $event->old_values['role'] ?? null),
                'action' => $event->action, 'changed_at' => $event->created_at?->toISOString(),
                'actor_name' => $event->user?->display_name,
                'exam_id' => $event->new_values['exam_id'] ?? null,
                'exam_name' => $exams->get($event->new_values['exam_id'] ?? null),
            ]);
        } elseif ($section === 'collaborations') {
            $query = ExamCollaborator::with(['exam.courseOffering.subject.careers', 'exam.courseOffering.academicTerm',
                'exam.courseOffering.teacher.user'])->where('user_id', $user->id);
            $active = app(ExamCollaboratorService::class)->getUserCollaborations($user->id)->modelKeys();
            $summary = ['active' => count($active), 'completed' => (clone $query)->whereNotIn('id', $active)->count()];
            ($filters['state'] ?? 'ACTIVE') === 'ACTIVE' ? $query->whereIn('id', $active) : $query->whereNotIn('id', $active);
            $this->searchOffering($query, $filters, 'exam.courseOffering');
            $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
            $page->through(fn ($row) => ['id' => $row->id, 'assigned_at' => $row->assigned_at?->toISOString(),
                'collaboration_status' => in_array($row->id, $active, true) ? 'ACTIVE' : 'COMPLETED',
                'exam_name' => $row->exam?->name, 'evaluation_type' => $row->exam?->evaluation_type,
            ] + $this->offering($row->exam?->courseOffering));
        } elseif (in_array($section, ['exams', 'exam-history'], true)) {
            abort_unless($type === 'teachers', 422);
            $query = Exam::with('courseOffering.subject.careers', 'courseOffering.academicTerm', 'courseOffering.teacher.user')
                ->whereHas('courseOffering', fn ($q) => $q->where('teacher_id', $profile->id));
            if ($section === 'exams') {
                app(ExamManagementService::class)->filterByView($query, 'upcoming');
            } else {
                $query->where(fn ($q) => app(ExamManagementService::class)->filterByView($q, 'past')->orWhere('exams.status', 'CANCELLED'));
            }
            $this->searchOffering($query, $filters, 'courseOffering');
            $page = $query->orderByDesc('exam_date')->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
            $page->through(fn ($row) => ['id' => $row->id, 'exam_name' => $row->name, 'exam_date' => substr($row->exam_date, 0, 10),
                'start_time' => $row->start_time, 'evaluation_type' => $row->evaluation_type, 'record_status' => $row->status,
            ] + $this->offering($row->courseOffering));
        } else {
            $query = CourseOffering::with('subject.careers', 'academicTerm', 'teacher.user');
            if ($type === 'teachers') {
                $query->where('teacher_id', $profile->id);
            } else {
                $query->join('enrollments', 'enrollments.course_offering_id', '=', 'course_offerings.id')
                    ->where('enrollments.student_id', $profile->id)->select('course_offerings.*', 'enrollments.status as enrollment_status');
            }
            $current = fn ($q) => $q->where('course_offerings.status', 'ACTIVE')
                ->whereHas('academicTerm', fn ($q) => $q->where('status', 'ACTIVE'));
            if ($section === 'subjects') {
                $query->where($current);
                if ($type === 'students') $query->where('enrollments.status', 'ACTIVE');
            } else {
                $query->where(function ($q) use ($type) {
                    $q->where('course_offerings.status', '!=', 'ACTIVE')
                        ->orWhereHas('academicTerm', fn ($q) => $q->where('status', '!=', 'ACTIVE'));
                    if ($type === 'students') $q->orWhere('enrollments.status', '!=', 'ACTIVE');
                });
            }
            $this->searchOffering($query, $filters, null);
            $page = $query->orderByDesc('course_offerings.id')->paginate($filters['per_page'] ?? 10);
            $page->through(fn ($row) => ['id' => $row->id, 'record_status' => $row->enrollment_status ?? $row->status,
                'final_result' => null] + $this->offering($row));
        }

        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()], 'summary' => $summary];
    }

    private function searchOffering($query, array $filters, ?string $relation): void
    {
        if (! ($search = trim($filters['search'] ?? ''))) return;
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $constraint = fn ($q) => $q->where(fn ($q) => $q
            ->whereHas('subject', fn ($s) => $s->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$term])->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$term]))
            ->orWhereHas('academicTerm', fn ($s) => $s->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$term]))
            ->orWhereHas('teacher', fn ($s) => $s->whereRaw("LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$term])));
        $relation ? $query->whereHas($relation, $constraint) : $constraint($query);
    }

    private function offering(?CourseOffering $offering): array
    {
        return ['subject_name' => $offering?->subject?->name, 'subject_code' => $offering?->subject?->code,
            'teacher_name' => $offering?->teacher ? trim($offering->teacher->first_names.' '.$offering->teacher->last_names) : null,
            'teacher_email' => $offering?->teacher?->user?->email, 'academic_term' => $offering?->academicTerm?->name,
            'careers' => $offering?->subject?->careers->pluck('name')->all() ?? [], 'group_code' => null];
    }
}
