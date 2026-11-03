<?php

namespace App\Services\Eligibility\Automation;

use App\Models\EligibilityPolicy;
use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfigurationService
{
    public function __construct(private CriteriaCatalog $catalog, private AutomationSupport $support) {}

    public function policy(Exam $exam): EligibilityPolicy
    {
        $config = DB::table('exam_eligibility_configurations')->where('exam_id', $exam->id)->first();

        return $config ? EligibilityPolicy::findOrFail($config->eligibility_policy_id)
            : EligibilityPolicy::where('status', 'ACTIVE')->orderByDesc('activated_at')->orderByDesc('id')->firstOrFail();
    }

    public function data(Exam $exam): array
    {
        $config = DB::table('exam_eligibility_configurations')->where('exam_id', $exam->id)->first();
        $policy = $this->policy($exam);
        $optional = DB::table('exam_eligibility_criteria as ec')->join('eligibility_criteria as c', 'c.id', '=', 'ec.eligibility_criterion_id')
            ->where('ec.exam_id', $exam->id)->whereNotNull('c.code')->where('ec.is_required', false)
            ->orderBy('c.code')->get()->map(fn ($row) => ['code' => $row->code, 'parameters' => json_decode($row->parameters ?: '{}', true)])->all();
        // A later exam-type change must not prevent reading/fixing its old configuration.
        $selected = array_map(fn ($c) => $c + ['required' => false, 'locked' => false], $optional);
        if ($exam->evaluation_type === 'makeup') {
            $selected[] = ['code' => CriteriaCatalog::SECOND, 'parameters' => [], 'required' => true, 'locked' => true];
        }

        return ['exam_id' => $exam->id, 'evaluation_type' => $exam->evaluation_type, 'configured_evaluation_type' => $config->evaluation_type ?? null, 'revision' => (int) ($config->revision ?? 0),
            'combination' => 'ALL', 'policy' => ['id' => $policy->id, 'version' => $policy->version, 'origin' => 'TEAM_PROPOSAL'],
            'selected_criteria' => array_values(array_filter($selected, fn ($c) => ! $c['required'])),
            'mandatory_criteria' => array_values(array_filter($selected, fn ($c) => $c['required'])),
            'criteria' => $selected];
    }

    public function options(Exam $exam): array
    {
        $data = $this->data($exam);
        $definition = $this->policy($exam)->definition;
        $data['assessments'] = [['key' => 'FIRST_PARTIAL', 'label' => 'Primer parcial'], ['key' => 'SECOND_PARTIAL', 'label' => 'Segundo parcial']];
        $data['criteria'] = [];
        foreach ($definition['allowed_criteria'] as $code) {
            if ($code === CriteriaCatalog::SECOND && $exam->evaluation_type !== 'makeup') {
                continue;
            }
            $required = $code === CriteriaCatalog::SECOND;
            $data['criteria'][] = ['code' => $code, 'label' => CriteriaCatalog::LABELS[$code], 'required' => $required,
                'locked' => $required, 'parameters' => $this->catalog->parameters($code)]
                + ($required ? ['operator' => 'GT', 'threshold' => $definition['second_instance']['threshold']] : []);
        }

        return $data;
    }

    public function save(Request $request, int $examId): array
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'policy_id' => ['sometimes', 'integer'],
            'criteria' => ['present', 'array', 'max:5'], 'criteria.*' => ['array:code,parameters'],
            'criteria.*.code' => ['required', 'string'], 'criteria.*.parameters' => ['present', 'array']]);

        return DB::transaction(function () use ($request, $examId, $input) {
            $exam = $this->support->exam($request, $examId, true);
            $old = $this->data($exam);
            if ($old['revision'] !== (int) $input['revision']) {
                $this->support->conflict('La configuración cambió. Actualiza antes de guardar.');
            }
            $policy = isset($input['policy_id']) ? EligibilityPolicy::findOrFail($input['policy_id']) : $this->policy($exam);
            abort_unless($policy->status === 'ACTIVE', 422, 'La política debe estar activa.');
            $selection = $this->catalog->selection($input['criteria'], (string) $exam->evaluation_type, $policy->definition);
            // Different offerings may initialize shared catalog codes concurrently.
            usort($selection, fn ($left, $right) => strcmp($left['code'], $right['code']));
            DB::table('exam_eligibility_configurations')->updateOrInsert(['exam_id' => $examId], [
                'eligibility_policy_id' => $policy->id, 'evaluation_type' => $exam->evaluation_type, 'revision' => $old['revision'] + 1, 'updated_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now()]);
            // Only manage coded HU34 rows; preserve pre-existing criteria owned by other modules.
            $coded = DB::table('eligibility_criteria')->whereNotNull('code')->pluck('id');
            DB::table('exam_eligibility_criteria')->where('exam_id', $examId)->whereIn('eligibility_criterion_id', $coded)->delete();
            foreach ($selection as $criterion) {
                DB::table('eligibility_criteria')->insertOrIgnore(['code' => $criterion['code'], 'name' => CriteriaCatalog::LABELS[$criterion['code']],
                    'source' => 'TEAM_PROPOSAL', 'criterion_type' => 'AUTOMATIC', 'created_by' => $request->user()->id,
                    'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
                $id = DB::table('eligibility_criteria')->where('code', $criterion['code'])->value('id');
                DB::table('exam_eligibility_criteria')->insert(['exam_id' => $examId, 'eligibility_criterion_id' => $id,
                    'is_required' => $criterion['required'], 'parameters' => json_encode($criterion['parameters'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            $data = $this->data($exam);
            $this->support->audit($request, 'ELIGIBILITY_CONFIGURATION_SAVED', 'Exam', $examId, $old, $data);

            return $data;
        });
    }
}
