<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;
use stdClass;

class IncidentQueryService
{
    public function __construct(private readonly IncidentAccessService $access, private readonly IncidentDataset $dataset) {}

    public function context(int $examId, User $actor): array
    {
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        $this->access->authorize($exam, User::findOrFail($actor->id));

        return ['exam' => ['id' => (int) $exam->id, 'name' => $exam->name,
            'subject_name' => $exam->courseOffering->subject->name], 'permissions' => ['can_create' => true]];
    }

    public function paginate(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $this->context($examId, $actor);
        $query = $this->query($examId);
        if (isset($filters['type'])) {
            $query->where('incidents.type', $filters['type'] === 'WRONG_EXAM' ? 'WRONG_EXAM_QR' : $filters['type']);
        }
        if (isset($filters['student_id'])) {
            $query->where('incidents.student_id', (string) $filters['student_id']);
        }
        if (isset($filters['origin'])) {
            $query->where('origin', $filters['origin'] === 'AUTOMATIC' ? 'AUTOMATIC_QR' : $filters['origin']);
        }

        $page = $query->orderByDesc('incidents.created_at')->orderBy('origin')->orderByDesc('source_id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
        if ($page->currentPage() > $page->lastPage()) {
            throw ValidationException::withMessages(['page' => 'La página solicitada está fuera de rango.']);
        }

        return $page;
    }

    public function find(int $examId, string $incidentId, User $actor): stdClass
    {
        $this->context($examId, $actor);

        $automatic = str_starts_with($incidentId, 'qr-');
        $sourceId = $automatic ? substr($incidentId, 3) : $incidentId;
        abort_unless(filter_var($sourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        if (! $automatic && (int) $sourceId > IncidentIdentity::AUTOMATIC_OFFSET) {
            $automatic = true;
            $sourceId = (int) $sourceId - IncidentIdentity::AUTOMATIC_OFFSET;
        }
        $record = $this->query($examId)->where('origin', $automatic ? 'AUTOMATIC_QR' : 'MANUAL')
            ->where('source_id', $sourceId)->first();
        abort_if($record === null, 404, 'Incidencia no encontrada.');

        return $record;
    }

    private function query(int $examId): Builder
    {
        return $this->dataset->withContext($this->dataset->query($examId));
    }
}
