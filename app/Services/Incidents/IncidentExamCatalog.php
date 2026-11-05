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
            ->join('subjects as subject', 'subject.id', '=', 'offering.subject_id');
        $words = preg_split('/\s+/u', mb_strtolower(trim((string) ($filters['search'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->whereRaw("LOWER(e.name || ' ' || subject.name) LIKE ? ESCAPE '!'", [$literal]);
        }
        $page = $query->select('e.id', 'e.name', 'subject.name as subject_name', 'e.exam_date')
            ->orderByDesc('e.exam_date')->orderByDesc('e.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        if ($page->currentPage() > $page->lastPage()) {
            throw ValidationException::withMessages(['page' => 'La página solicitada está fuera de rango.']);
        }

        return ['data' => $page->getCollection()->map(fn ($row) => ['id' => (int) $row->id,
            'name' => $row->name, 'subject_name' => $row->subject_name, 'exam_date' => $row->exam_date])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]];
    }
}
