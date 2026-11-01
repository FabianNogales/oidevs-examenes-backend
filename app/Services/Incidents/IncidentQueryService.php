<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use stdClass;

class IncidentQueryService
{
    public function __construct(private readonly IncidentAccessService $access, private readonly IncidentDataset $dataset) {}

    private function authorize(int $examId, User $actor): void
    {
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        $this->access->authorize($exam, User::findOrFail($actor->id));
    }

    public function paginate(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $this->authorize($examId, $actor);
        $query = $this->query($examId);
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (isset($filters['student_id'])) {
            $query->where('student_id', (string) $filters['student_id']);
        }
        if (isset($filters['origin'])) {
            $query->where('origin', $filters['origin']);
        }

        return $query->orderByDesc('created_at')->orderBy('origin')->orderByDesc('source_id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    public function find(int $examId, string $incidentId, User $actor): stdClass
    {
        $this->authorize($examId, $actor);

        $automatic = str_starts_with($incidentId, 'qr-');
        $sourceId = $automatic ? substr($incidentId, 3) : $incidentId;
        abort_unless(filter_var($sourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        $record = $this->query($examId)->where('origin', $automatic ? 'AUTOMATIC_QR' : 'MANUAL')
            ->where('source_id', $sourceId)->first();
        abort_if($record === null, 404, 'Incidencia no encontrada.');

        return $record;
    }

    private function query(int $examId): Builder
    {
        return $this->dataset->query($examId);
    }
}
