<?php

namespace App\Services\Entries;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EntryHistoryPresenter
{
    public function find(int $examId, int $entryId): array
    {
        $row = DB::table('exam_entries as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('e.exam_id', $examId)->where('e.id', $entryId)
            ->select('e.*', 's.first_names', 's.last_names', 's.sis_code', 's.identity_number', 'u.profile_photo', 'u.profile_image_id', 's.historical_photo', 's.historical_image_id')->first();
        abort_if($row === null, 404, 'Registro de ingreso no encontrado.');

        return $this->present(collect([$row]), false)->first();
    }

    public function present(Collection $rows, bool $canAnnul): Collection
    {
        $audits = AuditLog::where('action', 'EXAM_ENTRY_ANNULLED')->where('entity_type', 'ExamEntry')
            ->whereIn('entity_id', $rows->pluck('id'))->orderByDesc('id')->get()->unique('entity_id')->keyBy('entity_id');
        $userIds = $rows->pluck('verified_by')->merge($rows->pluck('annulled_by'))
            ->merge($audits->map(fn ($audit) => $audit->new_values['annulled_by'] ?? null))->filter()->unique();
        $users = User::with(['teacher', 'student'])->whereIn('id', $userIds)->get()->keyBy('id');

        return $rows->map(function ($row) use ($users, $audits, $canAnnul) {
            $snapshot = $audits->get($row->id)?->new_values ?? [];
            $annulledBy = $row->annulled_by ?? ($snapshot['annulled_by'] ?? null);
            $annulment = $row->status !== 'ANNULLED' ? null : [
                'reason' => $row->annulment_reason,
                'annulled_at' => $row->annulled_at === null ? null : $this->timestamp($row->annulled_at),
                'annulled_by' => $annulledBy === null ? null : [
                    'id' => (int) $annulledBy,
                    'name' => $snapshot['annulled_by_name'] ?? $users->get($annulledBy)?->display_name,
                ],
            ];
            $photo = $row->profile_image_id || $row->profile_photo;
            $canAnnulEntry = $canAnnul && $row->status === 'VALID';

            return [
                'student' => ['student_id' => (int) $row->student_id, 'first_names' => $row->first_names,
                    'last_names' => $row->last_names, 'sis_code' => (string) $row->sis_code,
                    'identity_number' => $row->identity_number === null ? null : (string) $row->identity_number,
                    'historical_photo_url' => ($row->historical_image_id || $row->historical_photo) ? route('entry-control.historical-photo', ['exam_id' => $row->exam_id, 'student_id' => $row->student_id, 'v' => $row->historical_image_id ?? 'legacy']) : null,
                    'profile_photo_url' => $photo ? route('entry-control.profile-photo', ['exam_id' => $row->exam_id, 'student_id' => $row->student_id, 'v' => $row->profile_image_id ?? 'legacy']) : null],
                'permissions' => ['can_annul' => $canAnnulEntry],
                'entry' => ['id' => (int) $row->id, 'exam_id' => (int) $row->exam_id,
                    'student_id' => (int) $row->student_id, 'room_id' => (int) $row->room_id,
                    'entered_at' => $this->timestamp($row->entered_at),
                    'verification_method' => $row->verification_method, 'verified_by' => (int) $row->verified_by,
                    'verified_by_name' => $snapshot['verified_by_name'] ?? $users->get($row->verified_by)?->display_name,
                    'status' => $row->status, 'annulment' => $annulment,
                    // Preserve the original backend projection for existing consumers.
                    'annulled_at' => $row->annulled_at === null ? null : $this->timestamp($row->annulled_at),
                    'annulled_by' => $row->annulled_by === null ? null : (int) $row->annulled_by,
                    'annulment_reason' => $row->annulment_reason, 'can_annul' => $canAnnulEntry],
            ];
        })->values();
    }

    private function timestamp(string $value): string
    {
        return Carbon::parse($value, config('app.timezone'))->toISOString();
    }
}
