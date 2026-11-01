<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

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
        // Read the original rejection event. No incident row is copied or created.
        $studentJson = DB::getDriverName() === 'pgsql'
            ? "new_values->>'student_id'"
            : "CAST(json_extract(new_values, '$.student_id') AS TEXT)";
        $examJson = DB::getDriverName() === 'pgsql'
            ? "new_values->>'qr_exam_id'"
            : "CAST(json_extract(new_values, '$.qr_exam_id') AS TEXT)";
        $manual = DB::table('special_cases')->where('exam_id', $examId)
            ->selectRaw("id AS source_id, exam_id, CAST(student_id AS TEXT) AS student_id, type, description,
                reported_by, created_at, status, 'MANUAL' AS origin, NULL AS qr_exam_id");
        $automatic = DB::table('audit_logs')->where('action', 'ENTRY_QR_WRONG_EXAM')
            ->where('entity_type', 'Exam')->where('entity_id', $examId)
            ->selectRaw("id AS source_id, entity_id AS exam_id, {$studentJson} AS student_id,
                'WRONG_EXAM_QR' AS type, 'Intento de ingreso rechazado: QR de otro examen.' AS description,
                user_id AS reported_by, created_at, 'REJECTED' AS status, 'AUTOMATIC_QR' AS origin,
                {$examJson} AS qr_exam_id");

        return DB::query()->fromSub($manual->unionAll($automatic), 'incidents');
    }
}
