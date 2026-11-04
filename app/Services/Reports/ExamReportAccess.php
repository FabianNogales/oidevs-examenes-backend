<?php

namespace App\Services\Reports;

use App\Enums\ExamReportType;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ExamReportAccess
{
    public function actor(User $actor): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isActive() && ($actor->hasRole(RoleName::DOCENTE) || $actor->hasRole(RoleName::ADMINISTRADOR)), 403,
            'No tiene autorización para consultar reportes de exámenes.');

        return $actor;
    }

    public function scope(User $actor): Builder
    {
        $actor = $this->actor($actor);
        $query = Exam::query();
        if (! $actor->hasRole(RoleName::ADMINISTRADOR)) {
            $query->whereHas('courseOffering.teacher', fn ($teacher) => $teacher->where('user_id', $actor->id));
        }

        return $query;
    }

    public function exam(int $id, User $actor): Exam
    {
        $actor = $this->actor($actor);
        $exam = Exam::with(['courseOffering.teacher', 'courseOffering.subject', 'courseOffering.academicTerm'])->findOrFail($id);
        abort_unless($actor->hasRole(RoleName::ADMINISTRADOR)
            || (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id, 403,
            'No tiene autorización para consultar reportes de este examen.');

        return $exam;
    }

    public function dto(Exam $exam): array
    {
        $offering = $exam->courseOffering;

        return ['id' => (int) $exam->id, 'name' => $exam->name, 'exam_date' => $exam->exam_date, 'status' => $exam->status,
            'subject' => ['id' => (int) $offering->subject_id, 'code' => $offering->subject->code, 'name' => $offering->subject->name],
            'academic_term' => ['id' => (int) $offering->academic_term_id, 'name' => $offering->academicTerm->name],
            'permissions' => ['report_types' => array_column(ExamReportType::cases(), 'value'), 'formats' => ['pdf', 'csv']]];
    }

    public function filters(Exam $exam, array $input): array
    {
        foreach (['subject_id', 'academic_term_id'] as $field) {
            if (isset($input[$field]) && (int) $input[$field] !== (int) $exam->courseOffering->{$field}) {
                throw ValidationException::withMessages([$field => 'El filtro no corresponde al examen seleccionado.']);
            }
        }

        return ['exam_id' => (int) $exam->id, 'subject_id' => (int) $exam->courseOffering->subject_id,
            'academic_term_id' => (int) $exam->courseOffering->academic_term_id,
            'date_from' => $input['date_from'] ?? null, 'date_to' => $input['date_to'] ?? null];
    }
}
