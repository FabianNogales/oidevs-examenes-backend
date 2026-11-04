<?php

namespace App\Services\Eligibility\Automation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GlobalCriteriaCatalog
{
    public function rows(bool $lock = false): Collection
    {
        return DB::table('eligibility_criteria')->whereIn('code', array_keys(CriteriaCatalog::LABELS))
            ->orderBy('code')->when($lock, fn ($query) => $query->lockForUpdate())->get();
    }

    public function revision(): string
    {
        return hash('sha256', $this->rows()->map(fn ($row) => [
            $row->code, (int) $row->revision, $row->name, $row->description, $row->status,
        ])->toJson());
    }

    public function definition(): array
    {
        $definition = app(CriteriaCatalog::class)->definition();
        $definition['allowed_criteria'] = $this->rows()->where('status', 'ACTIVE')->pluck('code')->all();

        return $definition;
    }

    public function data(object $row): array
    {
        $parameters = [];
        foreach (app(CriteriaCatalog::class)->parameters($row->code) as $key => $schema) {
            $parameters[] = ['key' => $key, 'required' => true] + collect($schema)->mapWithKeys(fn ($value, $key) => [match ($key) {
                'min' => 'minimum', 'max' => 'maximum', 'exclusive_min' => 'minimum_exclusive', default => $key,
            } => $value])->all();
        }

        return ['code' => $row->code, 'name' => $row->name, 'description' => $row->description,
            'status' => $row->status, 'revision' => (int) $row->revision,
            'parameters' => $parameters, 'mandatory_for_exam_types' => $row->code === CriteriaCatalog::SECOND ? ['makeup'] : [],
            'fixed_parameters' => $row->code === CriteriaCatalog::SECOND
                ? ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'operator' => 'GT', 'threshold' => 25] : null];
    }
}
