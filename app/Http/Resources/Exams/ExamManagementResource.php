<?php

namespace App\Http\Resources\Exams;

use App\Models\AcademicTerm;
use App\Services\Exams\ExamManagementPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['room', 'courseOffering.subject', 'courseOffering.academicTerm', 'courseOffering.teacher']);
        // A shared request context keeps period validation constant for a whole page.
        if (! $request->attributes->has('hu18.single_active_period')) {
            $request->attributes->set('hu18.single_active_period', AcademicTerm::where('status', 'ACTIVE')->limit(2)->get(['id'])->count() === 1);
        }
        $policy = app(ExamManagementPolicy::class);
        $permissions = $request->user()
            ? $policy->permissions($this->resource, $request->user(), $request->attributes->get('hu18.single_active_period'))
            : ['can_edit' => false, 'edit_block_reason' => 'Debes iniciar sesión.', 'can_cancel' => false, 'cancel_block_reason' => 'Debes iniciar sesión.'];

        return ['id' => (int) $this->id, 'course_offering_id' => (int) $this->course_offering_id,
            'name' => $this->name, 'subject_code' => $this->courseOffering?->subject?->code,
            'subject_name' => $this->courseOffering?->subject?->name, 'academic_term_name' => $this->courseOffering?->academicTerm?->name,
            'exam_date' => substr((string) $this->exam_date, 0, 10), 'start_time' => substr((string) $this->start_time, 0, 8),
            'duration_minutes' => (int) $this->duration_minutes,
            'room' => $this->room ? ['id' => (int) $this->room->id, 'code' => $this->room->code, 'name' => $this->room->name] : null,
            'evaluation_type' => $this->evaluation_type, 'rules' => $this->rules, 'status' => $this->status,
            'updated_at' => $this->updated_at?->toISOString()] + $permissions;
    }
}
