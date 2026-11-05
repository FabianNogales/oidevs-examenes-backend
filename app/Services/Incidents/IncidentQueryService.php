<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class IncidentQueryService
{
    public function __construct(private readonly IncidentAccessService $access, private readonly IncidentDataset $dataset,
        private readonly IncidentFilters $filters) {}

    public function context(int $examId, User $actor): array
    {
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        $permissions = $this->access->permissions($exam, User::findOrFail($actor->id));

        return ['exam' => ['id' => (int) $exam->id, 'name' => $exam->name,
            'subject_name' => $exam->courseOffering->subject->name], 'permissions' => $permissions];
    }

    public function paginate(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        return $this->listing($examId, $actor, $filters)['page'];
    }

    public function listing(int $examId, User $actor, array $filters): array
    {
        return $this->snapshot(function () use ($examId, $actor, $filters) {
            $context = $this->context($examId, $actor);
            $statuses = $this->statuses($examId);
            $this->validateStatus($filters, $statuses);
            $query = $this->filters->apply($this->query($examId), $filters, true);
            $byType = array_fill_keys(IncidentVocabulary::TYPES, 0);
            foreach ((clone $query)->reorder()->select('incidents.type')->selectRaw('COUNT(*) AS aggregate')
                ->groupBy('incidents.type')->get() as $group) {
                $type = IncidentVocabulary::type($group->type);
                $byType[$type] = ($byType[$type] ?? 0) + (int) $group->aggregate;
            }

            $page = $this->ordered($query)->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))
                ->withQueryString();
            if ($page->currentPage() > $page->lastPage()) {
                throw ValidationException::withMessages(['page' => 'La página solicitada está fuera de rango.']);
            }

            return ['page' => $page, 'context' => $context + [
                'summary' => ['total' => $page->total(), 'by_type' => $byType],
                'filters' => ['statuses' => $statuses],
                'message' => $page->total() === 0 ? 'No se encontraron incidencias con los criterios seleccionados.' : 'Incidencias consultadas correctamente.',
            ]];
        });
    }

    public function exportData(int $examId, User $actor, array $filters): array
    {
        return $this->snapshot(function () use ($examId, $actor, $filters) {
            $generatedAt = now()->toISOString();
            $context = $this->context($examId, $actor);
            abort_unless($context['permissions']['can_export'], 403, 'No tiene permiso para exportar estas incidencias.');
            $this->validateStatus($filters, $this->statuses($examId));
            $records = $this->ordered($this->filters->apply($this->query($examId), $filters, true))->get();

            return $context + ['records' => $records, 'report' => [
                'generated_at' => $generatedAt, 'timezone' => 'America/La_Paz', 'scope' => 'EXAM_INCIDENTS',
                'exam_id' => $examId, 'total' => $records->count(),
                'filters' => array_intersect_key($filters, array_flip(['search', 'type', 'status', 'date', 'date_from', 'date_to', 'origin', 'student_id'])),
            ]];
        });
    }

    private function statuses(int $examId): array
    {
        return $this->dataset->query($examId)->select('status')->distinct()->pluck('status')
            ->map(fn ($status) => IncidentVocabulary::status($status))->unique()->sort()->values()
            ->map(fn ($status) => ['value' => $status, 'label' => IncidentVocabulary::statusLabel($status)])->all();
    }

    private function validateStatus(array $filters, array $statuses): void
    {
        if (isset($filters['status']) && ! in_array($filters['status'], array_column($statuses, 'value'), true)) {
            throw ValidationException::withMessages(['status' => 'Selecciona un estado disponible para este examen.']);
        }
    }

    private function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('incidents.created_at')->orderBy('incidents.origin')->orderByDesc('incidents.source_id');
    }

    private function snapshot(callable $callback): array
    {
        return DB::transaction(function () use ($callback) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $callback();
        });
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
