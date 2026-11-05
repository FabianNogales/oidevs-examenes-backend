<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class ExamReportCatalog
{
    public function __construct(private readonly ExamReportAccess $access) {}

    public function consult(User $actor, array $filters): array
    {
        $scope = $this->access->scope($actor);
        $options = (clone $scope)->with(['courseOffering.subject', 'courseOffering.academicTerm'])->get();
        $subjects = $options->map(fn ($exam) => $exam->courseOffering->subject)->unique('id')->sortBy('name')->values();
        $terms = $options->map(fn ($exam) => $exam->courseOffering->academicTerm)->unique('id')->sortBy('name')->values();
        foreach (['subject_id' => $subjects, 'academic_term_id' => $terms] as $field => $allowed) {
            abort_if(isset($filters[$field]) && ! $allowed->contains('id', (int) $filters[$field]), 403,
                'El filtro solicitado está fuera del alcance autorizado.');
        }
        if (isset($filters['exam_id'])) {
            $this->access->exam((int) $filters['exam_id'], $actor);
            $scope->whereKey($filters['exam_id']);
        }
        foreach (['subject_id', 'academic_term_id'] as $field) {
            if (isset($filters[$field])) {
                $scope->whereHas('courseOffering', fn ($offering) => $offering->where($field, $filters[$field]));
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        foreach (preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $scope->where(fn ($query) => $query->whereRaw("LOWER(exams.name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('courseOffering.subject', fn ($subject) => $subject->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])));
        }
        $page = $scope->with(['courseOffering.subject', 'courseOffering.academicTerm'])->orderByDesc('exam_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        if ($page->currentPage() > $page->lastPage()) {
            throw ValidationException::withMessages(['page' => 'La página está fuera de rango.']);
        }

        return ['data' => $page->getCollection()->map($this->access->dto(...))->values(),
            'filters' => ['subjects' => $subjects->map(fn ($subject) => ['id' => (int) $subject->id, 'name' => $subject->name]),
                'academic_terms' => $terms->map(fn ($term) => ['id' => (int) $term->id, 'name' => $term->name])],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]];
    }
}
