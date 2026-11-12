<?php

namespace App\Services\Eligibility\Automation;

use App\Models\AcademicRecord;
use App\Models\EligibilityEvaluation;
use App\Models\Exam;
use App\Models\ExamEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluationService
{
    public function __construct(private AutomationSupport $support, private ConfigurationService $configuration,
        private AcademicDataService $academic, private CriteriaEvaluator $evaluator, private GlobalCriteriaCatalog $global) {}

    private function snapshot(Exam $exam): array
    {
        return ['configuration' => $this->configuration->data($exam),
            'exam' => $exam->only(['id', 'course_offering_id', 'evaluation_type', 'status', 'exam_date', 'start_time', 'duration_minutes']),
            'students' => $this->academic->activeStudents($exam->course_offering_id),
            'records' => AcademicRecord::where('course_offering_id', $exam->course_offering_id)->orderBy('student_id')->get()->map->only(['id', 'student_id', 'revision', 'assessments', 'attended_classes', 'total_classes'])->all(),
            'eligibilities' => ExamEligibility::where('exam_id', $exam->id)->orderBy('student_id')->get()->map->only(['id', 'student_id', 'status', 'reason', 'revision', 'decision_source'])->all()];
    }

    private function fingerprint(array $snapshot): string
    {
        // Stable JSON normalization also normalizes stdClass rows after a JSON round trip.
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function preview(Request $request, int $examId): EligibilityEvaluation
    {
        $input = $request->validate(['configuration_revision' => ['required', 'integer', 'min:1'],
            'catalog_revision' => ['required', 'string', 'size:64'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'filter' => ['sometimes', 'in:ALL,CHANGES,ELIGIBLE,INELIGIBLE,PENDING,PROTECTED'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return DB::transaction(function () use ($request, $examId, $input) {
            $this->global->rows(true);
            $exam = $this->support->exam($request, $examId, true);
            $configuration = $this->configuration->data($exam);
            if ($configuration['revision'] !== (int) $input['configuration_revision'] || $configuration['catalog_revision'] !== $input['catalog_revision']) {
                $this->support->conflict('Guarda o actualiza la configuración antes de evaluar.');
            }
            app(CriteriaCatalog::class)->selection(array_map(fn ($c) => array_intersect_key($c, ['code' => true, 'parameters' => true]), $configuration['selected_criteria']),
                (string) $exam->evaluation_type, $this->global->definition());
            if ($configuration['configured_evaluation_type'] !== $exam->evaluation_type) {
                $this->support->conflict('Cambió el tipo del examen. Revisa y guarda la configuración antes de evaluar.');
            }
            // Manual updates lock these same rows. Preserve a consistent preview of decisions.
            ExamEligibility::where('exam_id', $examId)->orderBy('student_id')->lockForUpdate()->get();
            $snapshot = $this->snapshot($exam);
            $policy = $this->configuration->policy($exam);
            $definition = $this->global->definition();
            $records = AcademicRecord::where('course_offering_id', $exam->course_offering_id)->get()->keyBy('student_id');
            $eligibilities = ExamEligibility::where('exam_id', $examId)->get()->keyBy('student_id');
            $results = [];
            foreach ($snapshot['students'] as $student) {
                $current = $eligibilities->get($student->id);
                $criteria = array_map(function ($criterion) use ($records, $student, $definition) {
                    $result = $this->evaluator->evaluate($criterion, $records->get($student->id), $definition);
                    $result['name'] = $criterion['name'];
                    $result['criterion_revision'] = $criterion['criterion_revision'];
                    $result['reason'] = $criterion['name'].': '.match ($result['status']) {
                        'PASSED' => 'cumple.', 'FAILED' => 'no cumple.', default => 'faltan datos académicos.',
                    };

                    return $result;
                }, $configuration['criteria']);
                $statuses = array_column($criteria, 'status');
                $computed = in_array('FAILED', $statuses, true) ? 'INELIGIBLE'
                    : (in_array('PENDING', $statuses, true) ? 'PENDING' : 'ELIGIBLE');
                $preserve = $current !== null && ! in_array($current->decision_source, ['AUTOMATIC', 'INITIALIZED'], true);
                $canApply = $computed !== 'PENDING' && ! $preserve;
                $currentStatus = $current?->status === 'NOT_ELIGIBLE' ? 'INELIGIBLE' : $current?->status;
                $reason = ! $criteria ? 'No se seleccionaron criterios automáticos.'
                    : implode(' ', array_column(array_values(array_filter($criteria, fn ($c) => $c['status'] !== 'PASSED')), 'reason'));
                if ($reason === '') {
                    $reason = 'Cumple todos los criterios seleccionados.';
                }
                $results[] = ['student_id' => (int) $student->id,
                    'student' => ['id' => (int) $student->id, 'display_name' => trim($student->first_names.' '.$student->last_names), 'sis' => $student->sis_code],
                    'computed_status' => $computed, 'current_status' => $currentStatus, 'effective_status' => $canApply ? $computed : $currentStatus,
                    'manual_override_preserved' => $preserve, 'will_update' => $canApply,
                    'state_will_change' => $canApply && $computed !== $currentStatus,
                    'traceability_only' => $canApply && $computed === $currentStatus,
                    'protected_source' => $preserve ? ($current->decision_source ?? 'LEGACY') : null,
                    'current_reason' => $current?->reason,
                    'effective_reason' => $canApply ? ($computed === 'INELIGIBLE' ? $reason : null) : $current?->reason,
                    'reason' => $reason, 'missing_fields' => array_values(array_unique(array_merge(...array_column($criteria, 'missing_fields')))),
                    'eligibility_revision' => $current?->revision, 'criteria_results' => $criteria];
            }
            $evaluation = EligibilityEvaluation::create(['preview_id' => (string) Str::uuid(), 'exam_id' => $examId,
                'eligibility_policy_id' => $policy->id, 'created_by' => $request->user()->id,
                'snapshot' => ['result_contract_version' => 2, 'fingerprint' => $this->fingerprint($snapshot), 'configuration' => $configuration,
                    'catalog' => $this->global->rows()->map(fn ($row) => $this->global->data($row))->all()],
                'results' => $results, 'expires_at' => now()->addMinutes(30)]);
            $this->support->audit($request, 'ELIGIBILITY_EVALUATION_PREVIEWED', 'Exam', $examId, null, ['evaluation_id' => $evaluation->id, 'preview_id' => $evaluation->preview_id]);

            return $evaluation;
        });
    }

    public function confirm(Request $request, int $examId, string $preview): array
    {
        return DB::transaction(function () use ($request, $examId, $preview) {
            $this->global->rows(true);
            $exam = $this->support->exam($request, $examId, true);
            $evaluation = EligibilityEvaluation::where('preview_id', $preview)->where('exam_id', $examId)->where('created_by', $request->user()->id)->lockForUpdate()->firstOrFail();
            if ($evaluation->confirmed_at) {
                return $evaluation->confirmation;
            }
            if (($evaluation->snapshot['result_contract_version'] ?? 1) !== 2 || $evaluation->expires_at->isPast()) {
                $this->support->conflict('La vista previa venció.');
            }
            $eligibilities = ExamEligibility::where('exam_id', $examId)->orderBy('student_id')->lockForUpdate()->get()->keyBy('student_id');
            if ($evaluation->snapshot['fingerprint'] !== $this->fingerprint($this->snapshot($exam))) {
                $this->support->conflict('Cambió el examen, configuración, inscripción, datos académicos o habilitaciones. Genera otro preview.');
            }
            $counts = ['evaluation_id' => $evaluation->id, 'updated' => 0, 'unchanged' => 0, 'manual_preserved' => 0, 'pending' => 0];
            foreach ($evaluation->results as $result) {
                if ($result['manual_override_preserved']) {
                    $counts['manual_preserved']++;
                }
                if ($result['computed_status'] === 'PENDING') {
                    $counts['pending']++;
                }
                if (! $result['will_update']) {
                    $counts['unchanged']++;

                    continue;
                }
                $studentId = $result['student']['id'];
                $row = $eligibilities->get($studentId) ?? new ExamEligibility(['exam_id' => $examId, 'student_id' => $studentId]);
                $old = $row->exists ? $row->toArray() : null;
                $row->forceFill(['status' => $result['computed_status'], 'reason' => $result['computed_status'] === 'INELIGIBLE' ? $result['reason'] : null,
                    'reason_code' => null, 'observations' => null, 'decision_source' => 'AUTOMATIC', 'automation_evaluation_id' => $evaluation->id,
                    'revision' => ($row->revision ?? 0) + 1, 'evaluated_by' => $request->user()->id, 'evaluated_at' => now()])->save();
                foreach ($result['criteria_results'] as $criterion) {
                    $criterionId = DB::table('eligibility_criteria')->where('code', $criterion['code'])->value('id');
                    DB::table('student_criterion_results')->updateOrInsert(['exam_id' => $examId, 'student_id' => $studentId, 'eligibility_criterion_id' => $criterionId],
                        ['result' => $criterion['status'], 'observation' => $criterion['reason'], 'evaluated_by' => $request->user()->id, 'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->support->audit($request, 'EXAM_ELIGIBILITY_AUTOMATED', 'ExamEligibility', $row->id, $old, $row->toArray());
                $counts['updated']++;
            }
            $evaluation->update(['confirmation' => $counts, 'confirmed_at' => now()]);
            $this->support->audit($request, 'ELIGIBILITY_EVALUATION_CONFIRMED', 'Exam', $examId, null, $counts);

            return $counts;
        });
    }

    public function view(Request $request, EligibilityEvaluation $evaluation): array
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'filter' => ['sometimes', 'in:ALL,CHANGES,ELIGIBLE,INELIGIBLE,PENDING,PROTECTED']]);
        $page = $request->integer('page', 1);
        $size = $request->integer('per_page', 10);
        $results = array_map(function ($result) {
            $result += ['will_update' => false, 'current_status' => null, 'manual_override_preserved' => false];
            $result += ['effective_status' => $result['current_status']];
            // Derive presentation flags without recalculating historical snapshots.
            $changes = $result['will_update'] && $result['effective_status'] !== $result['current_status'];

            return $result + ['state_will_change' => $changes,
                'traceability_only' => $result['will_update'] && ! $changes, 'protected_source' => null];
        }, $evaluation->results);
        $search = mb_strtolower(trim((string) $request->input('search', '')));
        $filter = $request->input('filter', 'ALL');
        $filtered = array_values(array_filter($results, function ($result) use ($search, $filter) {
            $student = $result['student'];
            if ($search !== '' && ! str_contains(mb_strtolower($student['display_name'] ?? ''), $search)
                && ! str_contains(mb_strtolower((string) ($student['sis'] ?? '')), $search)) {
                return false;
            }

            return match ($filter) {
                'CHANGES' => $result['state_will_change'],
                'ELIGIBLE', 'INELIGIBLE' => $result['effective_status'] === $filter,
                'PENDING' => $result['computed_status'] === 'PENDING',
                'PROTECTED' => $result['manual_override_preserved'],
                default => true,
            };
        }));

        return ['id' => $evaluation->id, 'preview_id' => $evaluation->preview_id, 'exam_id' => $evaluation->exam_id,
            'expires_at' => $evaluation->expires_at->toIso8601String(), 'confirmed_at' => $evaluation->confirmed_at?->toIso8601String(),
            'created_by' => $evaluation->created_by, 'created_at' => $evaluation->created_at->toIso8601String(),
            'configuration' => $evaluation->snapshot['configuration'],
            'catalog' => $evaluation->snapshot['catalog'] ?? null,
            // Retain the original definition for historical evaluations created before the catalog.
            'policy_definition' => $evaluation->snapshot['policy_definition'] ?? null,
            'confirmation' => $evaluation->confirmation,
            'results' => ['data' => array_slice($filtered, ($page - 1) * $size, $size),
                'meta' => ['current_page' => $page, 'per_page' => $size, 'total' => count($filtered), 'last_page' => max(1, (int) ceil(count($filtered) / $size))]],
            'summary' => ['total' => count($results), 'eligible' => count(array_filter($results, fn ($r) => $r['computed_status'] === 'ELIGIBLE')),
                'ineligible' => count(array_filter($results, fn ($r) => $r['computed_status'] === 'INELIGIBLE')), 'pending' => count(array_filter($results, fn ($r) => $r['computed_status'] === 'PENDING')),
                'effective_eligible' => count(array_filter($results, fn ($r) => $r['effective_status'] === 'ELIGIBLE')),
                'effective_ineligible' => count(array_filter($results, fn ($r) => $r['effective_status'] === 'INELIGIBLE')),
                'effective_unset' => count(array_filter($results, fn ($r) => $r['effective_status'] === null)),
                'state_changes' => count(array_filter($results, fn ($r) => $r['state_will_change'])),
                'traceability_only' => count(array_filter($results, fn ($r) => $r['traceability_only'])),
                'protected' => count(array_filter($results, fn ($r) => $r['manual_override_preserved']))]];
    }
}
