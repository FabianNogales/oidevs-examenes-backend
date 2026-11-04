<?php

namespace App\Services\Reports;

use App\Enums\ExamReportType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamReportService
{
    public function __construct(private readonly ExamReportAccess $access, private readonly ExamReportDataset $dataset,
        private readonly ExamReportSnapshots $snapshots) {}

    public function snapshot(int $examId, string $type, User $actor, array $input): array
    {
        $kind = ExamReportType::tryFrom($type);
        if ($kind === null) {
            throw ValidationException::withMessages(['type' => 'Selecciona un tipo de reporte válido.']);
        }
        if (isset($input['report_id'])) {
            $exam = $this->access->exam($examId, $actor);
            $snapshot = $this->snapshots->get($input['report_id']);
            abort_unless((int) $snapshot['actor_id'] === (int) $actor->id && $snapshot['report']['type'] === $type
                && $snapshot['report']['filters']['exam_id'] === $examId, 403, 'El reporte no pertenece a este usuario o contexto.');
            $original = $snapshot['report']['filters'];
            $current = $this->access->filters($exam, $input);
            foreach ($current as $field => $value) {
                if (in_array($field, ['date_from', 'date_to'], true) && ! array_key_exists($field, $input)) {
                    continue;
                }
                if ($original[$field] !== $value) {
                    throw ValidationException::withMessages([$field => 'Los filtros no coinciden con el reporte generado.']);
                }
            }
            $snapshot['exam']['permissions'] = $this->access->dto($exam)['permissions'];

            return $snapshot;
        }
        if ((int) ($input['page'] ?? 1) !== 1) {
            throw ValidationException::withMessages(['report_id' => 'Genera primero un reporte para consultar otras páginas.']);
        }
        $snapshot = DB::transaction(function () use ($examId, $kind, $actor, $input) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $generated = now()->toISOString();
            $exam = $this->access->exam($examId, $actor);
            $filters = $this->access->filters($exam, $input);
            $classification = $kind === ExamReportType::PENDING_ABSENT ? $this->dataset->classification($exam) : null;
            $rows = $this->dataset->rows($exam, $kind, $filters, $classification);

            return ['actor_id' => (int) $actor->id,
                'expires_at' => now()->addMinutes((int) config('exam_reports.snapshot_ttl_minutes'))->timestamp,
                'exam' => $this->access->dto($exam), 'rows' => $rows,
                'report' => ['id' => (string) Str::uuid(), 'type' => $kind->value, 'scope' => 'EXAM',
                    'generated_at' => $generated, 'source' => 'SERVER', 'offline_pending_included' => false,
                    'total_records' => count($rows), 'total_participants' => $this->dataset->participants($exam),
                    'pending_classification' => $classification, 'formats' => ['pdf', 'csv'], 'filters' => $filters,
                    'roster_scope' => 'ACTIVE_ENROLLMENTS']];
        });
        $this->snapshots->put($snapshot);

        return $snapshot;
    }

    public function page(int $examId, string $type, User $actor, array $input): array
    {
        $snapshot = $this->snapshot($examId, $type, $actor, $input);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 10);
        $total = $snapshot['report']['total_records'];
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($page > $lastPage) {
            throw ValidationException::withMessages(['page' => 'La página está fuera de rango.']);
        }

        return ['exam' => $snapshot['exam'], 'report' => $snapshot['report'],
            'data' => array_slice($snapshot['rows'], ($page - 1) * $perPage, $perPage),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total]];
    }
}
