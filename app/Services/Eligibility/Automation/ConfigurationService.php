<?php

namespace App\Services\Eligibility\Automation;

use App\Models\EligibilityPolicy;
use App\Models\Exam;
use App\Rules\PortalServicePlainText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfigurationService
{
    public function __construct(private CriteriaCatalog $catalog, private AutomationSupport $support,
        private GlobalCriteriaCatalog $global) {}

    // Legacy FK only. Global criteria, never an old policy definition, govern new evaluations.
    public function policy(Exam $exam): EligibilityPolicy
    {
        $id = DB::table('exam_eligibility_configurations')->where('exam_id', $exam->id)->value('eligibility_policy_id');

        return $id ? EligibilityPolicy::findOrFail($id) : EligibilityPolicy::orderBy('id')->firstOrFail();
    }

    public function data(Exam $exam): array
    {
        $config = DB::table('exam_eligibility_configurations')->where('exam_id', $exam->id)->first();
        $rows = $this->global->rows()->keyBy('code');
        $optional = DB::table('exam_eligibility_criteria as ec')->join('eligibility_criteria as c', 'c.id', '=', 'ec.eligibility_criterion_id')
            ->where('ec.exam_id', $exam->id)->whereIn('c.code', array_keys(CriteriaCatalog::LABELS))->where('ec.is_required', false)
            ->orderBy('c.code')->get()->map(fn ($row) => [
                'code' => $row->code, 'name' => $row->name, 'description' => $row->description,
                'status' => $row->status, 'criterion_revision' => (int) $row->revision,
                'parameters' => json_decode($row->parameters ?: '{}', true), 'required' => false, 'locked' => false,
            ]);
        $selected = $optional->where('status', 'ACTIVE')->values()->all();
        $mandatory = [];
        $second = $rows->get(CriteriaCatalog::SECOND);
        if ($exam->evaluation_type === 'makeup' && $second?->status === 'ACTIVE') {
            $mandatory[] = ['code' => CriteriaCatalog::SECOND, 'name' => $second->name, 'description' => $second->description,
                'status' => 'ACTIVE', 'criterion_revision' => (int) $second->revision,
                'parameters' => ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'operator' => 'GT', 'threshold' => 25],
                'required' => true, 'locked' => true];
        }

        return ['exam_id' => $exam->id, 'evaluation_type' => $exam->evaluation_type,
            'configured_evaluation_type' => $config->evaluation_type ?? null, 'revision' => (int) ($config->revision ?? 0),
            'catalog_revision' => $this->global->revision(), 'combination' => 'ALL',
            'selected_criteria' => $selected, 'mandatory_criteria' => $mandatory,
            'inactive_criteria' => $optional->where('status', 'INACTIVE')->values()->all(),
            'criteria' => array_merge($selected, $mandatory), 'manual_review_conditions' => $config->manual_review_conditions ?? null];
    }

    public function options(Exam $exam): array
    {
        $data = $this->data($exam);
        $data['assessments'] = [['key' => 'FIRST_PARTIAL', 'label' => 'Primer parcial'], ['key' => 'SECOND_PARTIAL', 'label' => 'Segundo parcial']];
        $data['criteria'] = $this->global->rows()->filter(fn ($row) => $row->status === 'ACTIVE'
            && ($row->code !== CriteriaCatalog::SECOND || $exam->evaluation_type === 'makeup'))
            ->map(fn ($row) => $this->global->data($row) + [
                'label' => $row->name, 'required' => $row->code === CriteriaCatalog::SECOND,
                'locked' => $row->code === CriteriaCatalog::SECOND,
            ])->values()->all();

        return $data;
    }

    public function save(Request $request, int $examId): array
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:0'],
            'catalog_revision' => ['required', 'string', 'size:64'], 'policy_id' => ['prohibited'],
            'criteria' => ['present', 'array', 'max:5'], 'criteria.*' => ['array:code,parameters'],
            'criteria.*.code' => ['required', 'string'], 'criteria.*.parameters' => ['present', 'array'],
            'manual_review_conditions' => ['sometimes', 'nullable', 'string', 'max:500', new PortalServicePlainText]]);

        return DB::transaction(function () use ($request, $examId, $input) {
            $this->global->rows(true);
            $exam = $this->support->exam($request, $examId, true);
            $old = $this->data($exam);
            if ($old['revision'] !== (int) $input['revision'] || $old['catalog_revision'] !== $input['catalog_revision']) {
                $this->support->conflict('La configuración o el catálogo cambió. Actualiza antes de guardar.');
            }
            $selection = $this->catalog->selection($input['criteria'], (string) $exam->evaluation_type, $this->global->definition());
            usort($selection, fn ($left, $right) => strcmp($left['code'], $right['code']));
            $values = ['eligibility_policy_id' => $this->policy($exam)->id, 'evaluation_type' => $exam->evaluation_type,
                'revision' => $old['revision'] + 1, 'updated_by' => $request->user()->id,
                'manual_review_conditions' => array_key_exists('manual_review_conditions', $input)
                    ? ($input['manual_review_conditions'] === '' ? null : $input['manual_review_conditions']) : $old['manual_review_conditions'], 'updated_at' => now()];
            if ($old['revision'] === 0) {
                $values['created_at'] = now();
            }
            DB::table('exam_eligibility_configurations')->updateOrInsert(['exam_id' => $examId], $values);
            // Preserve uncoded criteria owned by other modules.
            $coded = $this->global->rows()->pluck('id');
            DB::table('exam_eligibility_criteria')->where('exam_id', $examId)->whereIn('eligibility_criterion_id', $coded)->delete();
            foreach ($selection as $criterion) {
                $id = DB::table('eligibility_criteria')->where('code', $criterion['code'])->value('id');
                DB::table('exam_eligibility_criteria')->insert(['exam_id' => $examId, 'eligibility_criterion_id' => $id,
                    'is_required' => $criterion['required'], 'parameters' => json_encode($criterion['parameters'], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $data = $this->data($exam);
            $this->support->audit($request, 'ELIGIBILITY_CONFIGURATION_SAVED', 'Exam', $examId, $old, $data);

            return $data;
        });
    }
}
