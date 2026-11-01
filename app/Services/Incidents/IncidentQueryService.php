<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\SpecialCase;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class IncidentQueryService
{
    public function __construct(private readonly IncidentAccessService $access) {}

    private function authorize(int $examId, User $actor): void
    {
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        $this->access->authorize($exam, User::findOrFail($actor->id));
    }

    public function paginate(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($examId, $actor);
        $query = SpecialCase::query()->where('exam_id', $examId);
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    public function find(int $examId, int $incidentId, User $actor): SpecialCase
    {
        $this->authorize($examId, $actor);

        return SpecialCase::query()->where('exam_id', $examId)->findOrFail($incidentId);
    }
}
