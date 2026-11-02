<?php

namespace App\Services\Attendance;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\User;
use Illuminate\Database\Query\Builder;

class AttendanceCollaboratorActivity
{
    public function collect(Exam $exam, Builder $roster): array
    {
        $counts = (clone $roster)->whereNotNull('entry.id')->select('entry.verified_by')
            ->selectRaw('COUNT(*) AS aggregate')->groupBy('entry.verified_by')->get()->pluck('aggregate', 'verified_by');
        $assignments = ExamCollaborator::where('exam_id', $exam->id)->get(['user_id', 'status', 'revoked_at'])->keyBy('user_id');
        $ownerId = (int) $exam->courseOffering?->teacher?->user_id;
        $ids = $counts->keys()->merge($assignments->keys())->push($ownerId)->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $users = User::with(['teacher', 'student'])->whereIn('id', $ids)->get()->keyBy('id');

        return ['available' => true, 'metric' => 'VALID_ENTRIES_VERIFIED_BY_USER', 'progress_by_assigned_list_available' => false,
            'data' => $ids->map(function (int $id) use ($counts, $assignments, $users, $ownerId) {
                $assignment = $assignments->get($id);

                return ['user_id' => $id, 'name' => $users->get($id)?->display_name,
                    'role' => $id === $ownerId ? 'RESPONSIBLE_TEACHER' : ($assignment ? 'COLLABORATOR' : 'HISTORICAL_VERIFIER'),
                    'assignment_status' => $assignment?->status,
                    'valid_registered' => (int) $counts->get($id, 0),
                    'assigned_total' => null, 'pending' => null, 'attendance_percentage' => null,
                    'progress_unavailable_reason' => 'EXCLUSIVE_LISTS_NOT_CONFIGURED'];
            })->all()];
    }
}
