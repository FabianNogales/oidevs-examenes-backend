<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExamReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        if ($this->routeIs('exam-reports.export')) {
            return ['report_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
                'format' => ['required', Rule::in(['pdf', 'csv'])]];
        }
        $rules = ['subject_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'academic_term_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        if ($this->routeIs('exam-reports.catalog')) {
            return $rules + ['exam_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'search' => ['sometimes', 'nullable', 'string', 'max:255']];
        }
        $rules += ['report_id' => ['sometimes', 'required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
            'date_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'required', 'date_format:Y-m-d']];
        if ($this->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'Este parámetro no está permitido en el reporte.');
            }
        });
    }

    public function messages(): array
    {
        return ['date_from.*' => 'Indica una fecha inicial válida en formato AAAA-MM-DD.',
            'date_to.*' => 'Indica una fecha final válida, no anterior a la inicial.',
            'report_id.*' => 'Indica un identificador de reporte válido.',
            'format.*' => 'Selecciona pdf o csv.'];
    }
}
