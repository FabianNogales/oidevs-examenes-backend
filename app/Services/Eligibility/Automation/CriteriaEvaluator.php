<?php

namespace App\Services\Eligibility\Automation;

use App\Models\AcademicRecord;

class CriteriaEvaluator
{
    public function evaluate(array $criterion, ?AcademicRecord $record, array $policy): array
    {
        $code = $criterion['code'];
        $parameters = $criterion['parameters'];
        $assessments = $record?->assessments ?? [];
        $missing = [];
        $actual = null;
        $minimum = null;
        $passed = false;
        $operator = 'GTE';
        if ($code === 'MIN_ATTENDANCE_PERCENTAGE') {
            $minimum = (float) $parameters['minimum_percentage'];
            if ($record?->total_classes === null || $record?->attended_classes === null) {
                $missing = ['attended_classes', 'total_classes'];
            } else {
                $actual = $record->attended_classes * 100 / $record->total_classes;
                $passed = $actual >= $minimum;
            }
        } else {
            $keys = $code === CriteriaCatalog::SECOND ? CriteriaCatalog::ASSESSMENTS
                : ($code === 'MIN_ASSESSMENT_SCORE' ? [$parameters['assessment_key']] : $parameters['assessment_keys']);
            $scores = [];
            $notTaken = false;
            foreach ($keys as $key) {
                if (! isset($assessments[$key])) {
                    $missing[] = "assessments.$key";

                    continue;
                }
                if (! $assessments[$key]['was_taken']) {
                    $notTaken = true;

                    continue;
                }
                if ($code !== 'PREVIOUS_ASSESSMENTS_TAKEN') {
                    if ($assessments[$key]['score'] === null) {
                        $missing[] = "assessments.$key.score";
                    } else {
                        $scores[] = (int) round($assessments[$key]['score'] * 100);
                    }
                }
            }
            $minimum = match ($code) {
                'MIN_ASSESSMENT_SCORE' => (float) $parameters['minimum_score'],
                'MIN_ACCUMULATED_SCORE' => (float) $parameters['minimum_total'],
                'MIN_ASSESSMENT_AVERAGE' => (float) $parameters['minimum_average'],
                CriteriaCatalog::SECOND => (float) $policy['second_instance']['threshold'],
                default => true,
            };
            if ($code === CriteriaCatalog::SECOND) {
                $operator = 'GT';
            }
            // A known failed prerequisite remains FAILED even if another field is missing.
            if ($notTaken) {
                $missing = [];
                $actual = false;
            } elseif (! $missing) {
                $actual = match ($code) {
                    'PREVIOUS_ASSESSMENTS_TAKEN' => true,
                    'MIN_ACCUMULATED_SCORE' => array_sum($scores) / 100,
                    default => array_sum($scores) / (100 * count($scores)),
                };
                $passed = $code === 'PREVIOUS_ASSESSMENTS_TAKEN' || ($operator === 'GT' ? $actual > $minimum : $actual >= $minimum);
            }
        }
        $status = $missing ? 'PENDING' : ($passed ? 'PASSED' : 'FAILED');
        $reason = CriteriaCatalog::LABELS[$code].': '.match ($status) {
            'PASSED' => 'cumple.', 'FAILED' => 'no cumple.', default => 'faltan datos académicos.',
        };

        return ['code' => $code, 'status' => $status, 'actual_value' => $actual, 'required_value' => $minimum,
            'operator' => $operator, 'reason' => $reason, 'missing_fields' => $missing];
    }
}
