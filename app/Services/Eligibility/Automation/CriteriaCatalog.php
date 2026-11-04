<?php

namespace App\Services\Eligibility\Automation;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CriteriaCatalog
{
    public const ASSESSMENTS = ['FIRST_PARTIAL', 'SECOND_PARTIAL'];

    public const SECOND = 'SECOND_INSTANCE_PARTIAL_AVERAGE';

    public const LABELS = [
        'PREVIOUS_ASSESSMENTS_TAKEN' => 'Haber rendido las evaluaciones previas',
        'MIN_ATTENDANCE_PERCENTAGE' => 'Porcentaje mínimo de asistencia',
        'MIN_ASSESSMENT_SCORE' => 'Nota mínima en una evaluación previa',
        'MIN_ACCUMULATED_SCORE' => 'Puntaje acumulado mínimo',
        'MIN_ASSESSMENT_AVERAGE' => 'Promedio simple mínimo',
        self::SECOND => 'Promedio de los dos parciales estrictamente mayor al umbral de segunda instancia',
    ];

    public function definition(): array
    {
        return ['origin' => 'TEAM_PROPOSAL', 'combination' => 'ALL', 'allowed_criteria' => array_keys(self::LABELS),
            'second_instance' => ['evaluation_type' => 'makeup', 'operator' => 'GT', 'threshold' => 25]];
    }

    public function validateDefinition(array $definition): array
    {
        Validator::make($definition, [
            'origin' => ['required', Rule::in(['TEAM_PROPOSAL'])],
            'combination' => ['required', Rule::in(['ALL'])],
            'allowed_criteria' => ['required', 'array', 'min:1'],
            'allowed_criteria.*' => ['required', 'distinct', Rule::in(array_keys(self::LABELS))],
            'second_instance' => ['required', 'array:evaluation_type,operator,threshold'],
            'second_instance.evaluation_type' => ['required', Rule::in(['makeup'])],
            'second_instance.operator' => ['required', Rule::in(['GT'])],
            'second_instance.threshold' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ])->validate();
        if (! in_array(self::SECOND, $definition['allowed_criteria'], true)) {
            throw ValidationException::withMessages(['definition.allowed_criteria' => 'Debe incluir el criterio obligatorio de segunda instancia.']);
        }

        return array_intersect_key($definition, $this->definition());
    }

    public function parameters(string $code): array
    {
        $keys = ['type' => 'array', 'options' => self::ASSESSMENTS, 'min_items' => 1];

        return match ($code) {
            'PREVIOUS_ASSESSMENTS_TAKEN' => ['assessment_keys' => $keys],
            'MIN_ATTENDANCE_PERCENTAGE' => ['minimum_percentage' => ['type' => 'number', 'exclusive_min' => 0, 'max' => 100]],
            'MIN_ASSESSMENT_SCORE' => ['assessment_key' => ['type' => 'string', 'options' => self::ASSESSMENTS], 'minimum_score' => ['type' => 'number', 'min' => 0, 'max' => 100]],
            'MIN_ACCUMULATED_SCORE' => ['assessment_keys' => $keys, 'minimum_total' => ['type' => 'number', 'min' => 0, 'max' => 200]],
            'MIN_ASSESSMENT_AVERAGE' => ['assessment_keys' => $keys, 'minimum_average' => ['type' => 'number', 'min' => 0, 'max' => 100]],
            default => [],
        };
    }

    public function selection(array $criteria, string $type, array $definition): array
    {
        $seen = [];
        foreach ($criteria as $index => &$criterion) {
            $code = $criterion['code'] ?? '';
            $field = "criteria.$index";
            if (! in_array($code, $definition['allowed_criteria'], true) || $code === self::SECOND || isset($seen[$code])) {
                throw ValidationException::withMessages([$field => 'Criterio desconocido, duplicado o reservado como obligatorio.']);
            }
            $seen[$code] = true;
            $rules = [];
            foreach ($this->parameters($code) as $key => $schema) {
                $rules[$key] = ['required', $schema['type'] === 'number' ? 'numeric' : $schema['type']];
                if ($schema['type'] === 'number') {
                    $rules[$key][] = 'decimal:0,2';
                }
                if (isset($schema['min'])) {
                    $rules[$key][] = 'min:'.$schema['min'];
                }
                if (isset($schema['exclusive_min'])) {
                    $rules[$key][] = 'gt:0';
                }
                if (isset($schema['max'])) {
                    $rules[$key][] = 'max:'.$schema['max'];
                }
                if ($schema['type'] === 'string') {
                    $rules[$key][] = Rule::in(self::ASSESSMENTS);
                }
                if ($schema['type'] === 'array') {
                    $rules[$key][] = 'min:1';
                    $rules[$key][] = 'max:2';
                    $rules[$key.'.*'] = ['required', 'distinct', Rule::in(self::ASSESSMENTS)];
                }
            }
            $parameters = $criterion['parameters'] ?? [];
            if (array_diff(array_keys($parameters), array_keys($this->parameters($code)))) {
                throw ValidationException::withMessages([$field => 'Parámetros no admitidos.']);
            }
            $validated = Validator::make($parameters, $rules)->validate();
            if (isset($validated['assessment_keys'])) {
                sort($validated['assessment_keys']);
            }
            if ($code === 'MIN_ACCUMULATED_SCORE' && $validated['minimum_total'] > count($validated['assessment_keys']) * 100) {
                throw ValidationException::withMessages([$field => 'El puntaje supera el máximo posible.']);
            }
            if ($type === 'makeup' && in_array(self::SECOND, $definition['allowed_criteria'], true) && $code === 'MIN_ASSESSMENT_AVERAGE' && count($validated['assessment_keys']) === 2) {
                $message = 'El promedio de ambos parciales ya está definido por el criterio obligatorio de segunda instancia.';
                throw new HttpResponseException(response()->json(['code' => 'CRITERIA_CONFLICT', 'message' => $message, 'errors' => [$field => [$message]]], 422));
            }
            $criterion = ['code' => $code, 'parameters' => $validated, 'required' => false, 'locked' => false];
        }
        unset($criterion);
        if ($type === 'makeup' && in_array(self::SECOND, $definition['allowed_criteria'], true)) {
            $criteria[] = ['code' => self::SECOND, 'parameters' => ['assessment_keys' => self::ASSESSMENTS, 'operator' => 'GT', 'threshold' => 25], 'required' => true, 'locked' => true];
        }

        return $criteria;
    }
}
