<?php

namespace App\Services\Incidents;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncidentExamCatalog
{
    public function consult(User $actor, array $filters): array
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isActive() && $actor->hasRole(RoleName::ADMINISTRADOR), 403);
        $query = DB::table('exams as e')->join('course_offerings as offering', 'offering.id', '=', 'e.course_offering_id')
            ->join('subjects as subject', 'subject.id', '=', 'offering.subject_id')
            ->join('academic_terms as term', 'term.id', '=', 'offering.academic_term_id');
        foreach (['academic_term_id' => 'offering.academic_term_id', 'subject_id' => 'offering.subject_id',
            'course_offering_id' => 'offering.id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['evaluation_type'])) {
            $query->whereRaw('LOWER(e.evaluation_type) = ?', [$filters['evaluation_type']]);
        }
        if (isset($filters['career_id'])) {
            $query->whereExists(fn ($query) => $query->selectRaw('1')->from('career_subject as cs')
                ->whereColumn('cs.subject_id', 'subject.id')->where('cs.career_id', $filters['career_id']));
        }
        $words = preg_split('/\s+/u', mb_strtolower(trim((string) ($filters['search'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->whereRaw("LOWER(e.name || ' ' || subject.name) LIKE ? ESCAPE '!'", [$literal]);
        }
        $page = $query->select('e.id', 'e.name', 'subject.name as subject_name', 'e.exam_date',
            'e.course_offering_id', 'e.evaluation_type', 'offering.subject_id', 'offering.academic_term_id', 'term.name as academic_term_name')
            ->orderByDesc('e.exam_date')->orderByDesc('e.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        if ($page->currentPage() > $page->lastPage()) {
            throw ValidationException::withMessages(['page' => 'La página solicitada está fuera de rango.']);
        }

        return ['data' => $page->getCollection()->map(fn ($row) => ['id' => (int) $row->id,
            'name' => $row->name, 'subject_name' => $row->subject_name, 'exam_date' => $row->exam_date,
            'course_offering_id' => (int) $row->course_offering_id, 'subject_id' => (int) $row->subject_id,
            'academic_term_id' => (int) $row->academic_term_id, 'academic_term_name' => $row->academic_term_name,
            'evaluation_type' => $row->evaluation_type])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]];
    }
}
