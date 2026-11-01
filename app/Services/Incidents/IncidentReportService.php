<?php

namespace App\Services\Incidents;

use App\Enums\IncidentType;
use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;
use stdClass;

class IncidentReportService
{
    public function __construct(private readonly IncidentDataset $dataset, private readonly IncidentFilters $filters) {}

    private function authorizedQuery(User $actor, array $filters): Builder
    {
        $actor = User::findOrFail($actor->id);
        $admin = $actor->hasRole(RoleName::ADMINISTRADOR);
        abort_unless($actor->isActive() && ($admin || $actor->hasRole(RoleName::DOCENTE)), 403,
            'Solo el administrador o el docente responsable pueden consultar y exportar estos reportes.');
        $examId = isset($filters['exam_id']) ? (int) $filters['exam_id'] : null;
        if ($examId !== null) {
            $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
            abort_unless($admin || (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id, 403,
                'No tiene autorización para consultar incidencias de este examen.');
        }
        $query = $this->dataset->withContext($this->dataset->query($examId));
        if (! $admin) {
            $query->whereIn('incidents.exam_id', Exam::query()->select('exams.id')
                ->whereHas('courseOffering.teacher', fn ($teacher) => $teacher->where('user_id', $actor->id)));
        }

        return $this->filters->apply($query, $filters);
    }

    private function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('incidents.created_at')->orderBy('incidents.origin')->orderByDesc('incidents.source_id');
    }

    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->ordered($this->authorizedQuery($actor, $filters))
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1))->withQueryString();
    }

    public function summary(User $actor, array $filters): array
    {
        $query = $this->authorizedQuery($actor, $filters);
        $byType = array_fill_keys(array_column(IncidentType::cases(), 'value'), 0);
        $byOrigin = ['MANUAL' => 0, 'AUTOMATIC_QR' => 0];
        $byStatus = [];
        $total = 0;
        foreach ((clone $query)->reorder()->select('incidents.type', 'incidents.origin', 'incidents.status')
            ->selectRaw('COUNT(*) AS aggregate')->groupBy('incidents.type', 'incidents.origin', 'incidents.status')->get() as $group) {
            $count = (int) $group->aggregate;
            $byType[$group->type] = ($byType[$group->type] ?? 0) + $count;
            $byOrigin[$group->origin] += $count;
            $byStatus[$group->status] = ($byStatus[$group->status] ?? 0) + $count;
            $total += $count;
        }

        return ['total' => $total, 'by_type' => $byType, 'by_origin' => $byOrigin, 'by_status' => $byStatus];
    }

    public function find(User $actor, string $id, array $filters = []): stdClass
    {
        $query = $this->authorizedQuery($actor, $filters);
        $automatic = str_starts_with($id, 'qr-');
        $sourceId = $automatic ? substr($id, 3) : $id;
        abort_unless(filter_var($sourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);
        $record = $query->where('incidents.source_id', $sourceId)
            ->where('incidents.origin', $automatic ? 'AUTOMATIC_QR' : 'MANUAL')->first();
        abort_if($record === null, 404, 'Incidencia no encontrada.');

        return $record;
    }

    public function export(User $actor, array $filters): LazyCollection
    {
        return $this->ordered($this->authorizedQuery($actor, $filters))->cursor();
    }
}
