<?php

namespace App\Http\Resources\Entries;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ExamEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id, 'exam_id' => (int) $this->exam_id,
            'student_id' => (int) $this->student_id, 'room_id' => (int) $this->room_id,
            'entered_at' => Carbon::parse($this->entered_at, config('app.timezone'))->toISOString(),
            'verification_method' => $this->verification_method, 'verified_by' => (int) $this->verified_by,
            'verified_by_name' => User::with(['teacher', 'student'])->find($this->verified_by)?->display_name,
            'status' => $this->status,
            'annulled_at' => $this->annulled_at === null ? null
                : Carbon::parse($this->annulled_at, config('app.timezone'))->toISOString(),
            'annulled_by' => $this->annulled_by === null ? null : (int) $this->annulled_by,
            'annulment_reason' => $this->annulment_reason,
            'can_annul' => false,
        ];
    }
}
