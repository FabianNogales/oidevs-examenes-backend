<?php

namespace App\Services\Discipline;

use App\Models\DisciplineCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisciplineQuery
{
    public function listing(int $examId, array $filters, Request $request, bool $all = false)
    {
        $context = app(DisciplineService::class)->context($examId, $request);
        $query = DisciplineCase::query()->where('discipline_cases.exam_id', $examId)
            ->leftJoin('special_cases as i', fn ($join) => $join->on('i.id', '=', 'discipline_cases.source_id')->where('discipline_cases.source_type', 'INCIDENT'))
            ->leftJoin('infractions as f', fn ($join) => $join->on('f.id', '=', 'discipline_cases.source_id')->where('discipline_cases.source_type', 'INFRACTION'))
            ->leftJoin('expulsions as old', fn ($join) => $join->on('old.id', '=', 'discipline_cases.source_id')->where('discipline_cases.source_type', 'LEGACY_EXPULSION'))
            ->leftJoin('expulsions as measure', 'measure.discipline_case_id', '=', 'discipline_cases.id')
            ->leftJoin('audit_logs as qr', fn ($join) => $join->on('qr.id', '=', 'discipline_cases.source_id')->where('discipline_cases.source_type', 'QR'));
        $qrStudent = DB::getDriverName() === 'pgsql' ? "qr.new_values->>'student_id'" : "CAST(json_extract(qr.new_values, '$.student_id') AS TEXT)";
        $query->leftJoin('students as student', DB::raw('CAST(student.id AS TEXT)'), '=', DB::raw("COALESCE(CAST(i.student_id AS TEXT), CAST(f.student_id AS TEXT), CAST(old.student_id AS TEXT), {$qrStudent})"));
        if (! $context['permissions']['can_manage']) $query->whereIn('discipline_cases.source_type', ['INCIDENT', 'QR']);
        if (! empty($filters['request_key'])) $query->where('discipline_cases.request_actor_id', $request->user()->id)->where('discipline_cases.request_key', $filters['request_key']);
        if (! empty($filters['kind'])) $query->whereIn('discipline_cases.source_type', $filters['kind'] === 'INCIDENT' ? ['INCIDENT', 'QR'] : [$filters['kind']]);
        if (! empty($filters['search'])) {
            $needle = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function ($q) use ($needle) {
                foreach (['i.description', 'f.description', 'old.reason', 'student.first_names', 'student.last_names', 'student.sis_code', 'student.identity_number'] as $field) {
                    $q->orWhereRaw("LOWER(COALESCE({$field}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['measure'])) $filters['measure'] === 'EXPELLED' ? $query->whereNotNull('measure.id') : $query->whereNull('measure.id');
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if (! empty($filters[$field])) {
                $date = \Carbon\CarbonImmutable::parse($filters[$field], 'America/La_Paz');
                $query->where('discipline_cases.occurred_at', $operator, $field === 'date_from' ? $date->startOfDay() : $date->endOfDay());
            }
        }
        $query->select('discipline_cases.*')->orderByDesc('discipline_cases.occurred_at')->orderByDesc('discipline_cases.id');
        return $all ? $query->get() : $query->paginate($filters['per_page'] ?? 15);
    }
}
