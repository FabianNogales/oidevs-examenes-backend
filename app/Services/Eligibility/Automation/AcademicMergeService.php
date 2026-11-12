<?php

namespace App\Services\Eligibility\Automation;

use App\Models\AcademicImport;
use App\Models\AcademicRecord;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicMergeService
{
    public function __construct(private AutomationSupport $support, private AcademicDataService $academic) {}

    private function values(?AcademicRecord $record): array
    {
        return ['assessments' => $record?->assessments ?? [],
            'attended_classes' => $record?->attended_classes, 'total_classes' => $record?->total_classes];
    }

    private function changes(array $row): array
    {
        $key = $row['assessment_key'] ?? '';
        $taken = $row['was_taken'] ?? '';
        $score = $row['score'] ?? '';
        $attended = $row['attended_classes'] ?? '';
        $total = $row['total_classes'] ?? '';
        Validator::make($row, ['sis' => ['required', 'string', 'max:50'],
            'assessment_key' => ['sometimes', 'nullable', Rule::in(['', ...CriteriaCatalog::ASSESSMENTS])],
            'was_taken' => ['sometimes', 'nullable', Rule::in(['', 'true', 'false'])]])->validate();
        if ($key === '' && ($taken !== '' || $score !== '')) {
            throw ValidationException::withMessages(['assessment_key' => 'Indica la evaluación de la nota o condición de rendido.']);
        }
        if (($attended === '') !== ($total === '')) {
            throw ValidationException::withMessages(['attendance' => 'Proporciona ambas cantidades de asistencia.']);
        }
        $changes = [];
        if ($key !== '' && ($taken !== '' || $score !== '')) {
            $assessment = [];
            if ($taken !== '') {
                $assessment['was_taken'] = $taken === 'true';
            }
            if ($score !== '') {
                $assessment['score'] = $score;
            }
            $changes['assessments'] = [$key => $assessment];
        }
        if ($total !== '') {
            $changes['attended_classes'] = $attended;
            $changes['total_classes'] = $total;
        }
        if (! $changes) {
            throw ValidationException::withMessages(['row' => 'La fila no contiene datos para importar.']);
        }

        return $changes;
    }

    public function preview(Request $request, int $offering, UploadedFile $file): AcademicImport
    {
        $rows = $this->academic->readRows($file, true);

        return DB::transaction(function () use ($request, $offering, $rows) {
            $this->support->offering($request, $offering, true);
            $students = collect($this->academic->activeStudents($offering))->keyBy('sis_code');
            $records = AcademicRecord::where('course_offering_id', $offering)->orderBy('student_id')->lockForUpdate()->get()->keyBy('student_id');
            $groups = [];
            $seen = [];
            $errors = [];
            foreach ($rows as $index => $row) {
                try {
                    if ($row === null) {
                        throw ValidationException::withMessages(['row' => 'Cantidad incorrecta de columnas.']);
                    }
                    $changes = $this->changes($row);
                    $student = $students->get($row['sis']);
                    if (! $student) {
                        throw ValidationException::withMessages(['sis' => 'Estudiante inexistente o sin inscripción activa en esta oferta.']);
                    }
                    $record = $records->get($student->id);
                    $before = $this->values($record);
                    $group = $groups[$student->id] ?? ['student_id' => (int) $student->id, 'sis' => $student->sis_code,
                        'record_id' => $record?->id, 'revision' => $record?->revision, 'before' => $before, 'proposed' => $before];
                    $fields = isset($changes['assessments']) ? ['assessment:'.array_key_first($changes['assessments'])] : [];
                    if (array_key_exists('total_classes', $changes)) {
                        $fields[] = 'attendance';
                    }
                    foreach ($fields as $field) {
                        if (isset($seen[$student->id][$field])) {
                            throw ValidationException::withMessages(['row' => 'Datos duplicados para el mismo estudiante: '.$field]);
                        }
                    }
                    // Empty CSV cells never erase an existing note or attendance.
                    foreach ($changes['assessments'] ?? [] as $key => $assessment) {
                        $previous = $group['proposed']['assessments'][$key] ?? ['score' => null];
                        $group['proposed']['assessments'][$key] = array_replace($previous, $assessment);
                    }
                    unset($changes['assessments']);
                    $group['proposed'] = $this->academic->validateRecord(array_replace($group['proposed'], $changes));
                    foreach ($fields as $field) {
                        $seen[$student->id][$field] = true;
                    }
                    $groups[$student->id] = $group;
                } catch (ValidationException $exception) {
                    $errors[] = ['row' => $index + 2, 'sis' => $row['sis'] ?? null, 'messages' => $exception->errors()];
                }
            }
            $summary = ['created_records' => 0, 'updated_records' => 0, 'unchanged_records' => 0];
            foreach ($groups as &$group) {
                $group['action'] = $group['record_id'] === null ? 'CREATE'
                    : ($this->academic->validateRecord($group['before']) === $group['proposed'] ? 'UNCHANGED' : 'UPDATE');
                $counter = match ($group['action']) {
                    'CREATE' => 'created_records', 'UPDATE' => 'updated_records', default => 'unchanged_records',
                };
                $summary[$counter]++;
                // Preserve the fields consumed by the existing import preview.
                $group += $group['proposed'];
            }
            unset($group);
            $reason = trim((string) $request->input('reason', ''));
            if ($summary['updated_records'] > 0 && $reason === '') {
                throw ValidationException::withMessages(['reason' => 'Justifica la actualización de datos académicos existentes.']);
            }
            $preview = AcademicImport::create(['id' => (string) Str::uuid(), 'course_offering_id' => $offering,
                'created_by' => $request->user()->id, 'expires_at' => now()->addMinutes(30),
                'preview' => ['mode' => 'MERGE', 'reason' => $reason, 'total_rows' => count($rows),
                    'valid_records' => array_values($groups), 'errors' => $errors, 'summary' => $summary]]);
            $this->support->audit($request, 'ACADEMIC_IMPORT_PREVIEWED', 'CourseOffering', $offering, null,
                ['preview_id' => $preview->id, 'mode' => 'MERGE', 'total_rows' => count($rows), 'errors' => count($errors)]);

            return $preview;
        });
    }

    // Caller holds the offering and import locks and checks expiry and retry status.
    public function confirm(Request $request, int $offering, AcademicImport $import): array
    {
        $activeIds = array_map(fn ($student) => (int) $student->id, $this->academic->activeStudents($offering));
        $result = ['preview_id' => $import->id, 'created_records' => 0, 'updated_records' => 0, 'unchanged_records' => 0, 'record_ids' => []];
        foreach ($import->preview['valid_records'] as $entry) {
            $record = AcademicRecord::where('course_offering_id', $offering)->where('student_id', $entry['student_id'])->lockForUpdate()->first();
            if (! in_array($entry['student_id'], $activeIds, true) || $record?->id !== $entry['record_id']
                || $record?->revision !== $entry['revision'] || $this->values($record) !== $entry['before']) {
                $this->support->conflict('Cambió la inscripción o el registro académico. Genera otro preview MERGE.');
            }
            if ($entry['action'] === 'UNCHANGED') {
                $result['unchanged_records']++;
                $result['record_ids'][] = $record->id;

                continue;
            }
            $old = $record?->toArray();
            $record ??= new AcademicRecord(['course_offering_id' => $offering, 'student_id' => $entry['student_id']]);
            $record->fill($entry['proposed'] + ['revision' => ($record->revision ?? 0) + 1, 'updated_by' => $request->user()->id])->save();
            $action = $entry['action'] === 'CREATE' ? 'ACADEMIC_RECORD_IMPORTED' : 'ACADEMIC_RECORD_CORRECTED';
            $this->support->audit($request, $action, 'AcademicRecord', $record->id, $old,
                $record->toArray() + ['mode' => 'MERGE', 'correction_reason' => $import->preview['reason']]);
            $result[$entry['action'] === 'CREATE' ? 'created_records' : 'updated_records']++;
            $result['record_ids'][] = $record->id;
        }
        $import->update(['confirmed_at' => now(), 'result' => $result]);
        $this->support->audit($request, 'ACADEMIC_IMPORT_CONFIRMED', 'CourseOffering', $offering, null, $result + ['mode' => 'MERGE']);

        return $result;
    }
}
