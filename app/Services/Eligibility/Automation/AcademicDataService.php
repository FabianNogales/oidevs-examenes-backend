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

class AcademicDataService
{
    public const HEADER = ['sis', 'assessment_key', 'was_taken', 'score', 'attended_classes', 'total_classes'];

    public function __construct(private AutomationSupport $support) {}

    public function validateRecord(array $input): array
    {
        $data = Validator::make($input, [
            'assessments' => ['present', 'array', 'max:2'],
            'assessments.*' => ['required', 'array:was_taken,score'],
            'assessments.*.was_taken' => ['required', 'boolean'],
            'assessments.*.score' => ['present', 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'attended_classes' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'total_classes' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        if (array_diff(array_keys($data['assessments']), CriteriaCatalog::ASSESSMENTS)) {
            throw ValidationException::withMessages(['assessments' => 'Evaluación no admitida.']);
        }
        foreach ($data['assessments'] as &$assessment) {
            $assessment['was_taken'] = (bool) $assessment['was_taken'];
            if (! $assessment['was_taken'] && $assessment['score'] !== null) {
                throw ValidationException::withMessages(['assessments' => 'Una evaluación no rendida no puede tener nota.']);
            }
            $assessment['score'] = $assessment['score'] === null ? null : (float) $assessment['score'];
        }
        unset($assessment);
        if (($data['attended_classes'] === null) !== ($data['total_classes'] === null)
            || ($data['total_classes'] !== null && $data['attended_classes'] > $data['total_classes'])) {
            throw ValidationException::withMessages(['attendance' => 'Proporciona ambas cantidades de asistencia; asistencias no puede superar el total.']);
        }
        ksort($data['assessments']);
        $data['attended_classes'] = $data['attended_classes'] === null ? null : (int) $data['attended_classes'];
        $data['total_classes'] = $data['total_classes'] === null ? null : (int) $data['total_classes'];
        if (! $data['assessments'] && $data['total_classes'] === null) {
            throw ValidationException::withMessages(['assessments' => 'Proporciona una evaluación o datos de asistencia.']);
        }

        return $data;
    }

    public function activeStudents(int $offering): array
    {
        return DB::table('enrollments')->join('students', 'students.id', '=', 'enrollments.student_id')
            ->where('enrollments.course_offering_id', $offering)->where('enrollments.status', 'ACTIVE')
            ->orderBy('students.id')->select('students.id', 'students.sis_code', 'students.first_names', 'students.last_names')->get()->all();
    }

    public function readRows(UploadedFile $file, bool $merge = false): array
    {
        $raw = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw ValidationException::withMessages(['file' => 'Debe ser CSV UTF-8 válido.']);
        }
        $handle = fopen($file->getRealPath(), 'rb');
        $rows = [];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if ($merge ? (! $header || ! in_array('sis', $header, true) || count($header) !== count(array_unique($header))
                || array_diff($header, self::HEADER)) : $header !== self::HEADER) {
                throw ValidationException::withMessages(['file' => 'Encabezado requerido: '.implode(',', self::HEADER)]);
            }
            $offset = ftell($handle);
            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $next = ftell($handle);
                $record = substr($raw, $offset, $next - $offset);
                $offset = $next;
                $field = '(?:[^",\r\n]*|"(?:[^"]|"")*")';
                if (preg_match('/\A'.$field.'(?:,'.$field.')*(?:\r\n|\r|\n)?\z/', $record) !== 1) {
                    throw ValidationException::withMessages(['file' => 'CSV con comillas o formato inválido.']);
                }
                if ($fields === [null]) {
                    continue;
                }
                if (count($rows) >= 1000) {
                    throw ValidationException::withMessages(['file' => 'Máximo 1000 filas.']);
                }
                $rows[] = count($fields) === count($header) ? array_combine($header, array_map('trim', $fields)) : null;
            }
        } finally {
            fclose($handle);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'El archivo no contiene filas.']);
        }

        return $rows;
    }

    public function preview(Request $request, int $offering, UploadedFile $file): AcademicImport
    {
        if ($request->input('mode', 'CREATE_ONLY') === 'MERGE') {
            return app(AcademicMergeService::class)->preview($request, $offering, $file);
        }
        $rows = $this->readRows($file);
        $students = collect($this->activeStudents($offering))->keyBy('sis_code');
        $existing = AcademicRecord::where('course_offering_id', $offering)->pluck('student_id')->all();
        $errors = [];
        $groups = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            try {
                if ($row === null) {
                    throw ValidationException::withMessages(['row' => 'Cantidad incorrecta de columnas.']);
                }
                Validator::make($row, ['sis' => ['required', 'string', 'max:50'], 'assessment_key' => ['required', Rule::in(CriteriaCatalog::ASSESSMENTS)],
                    'was_taken' => ['required', Rule::in(['true', 'false'])]])->validate();
                $student = $students->get($row['sis']);
                if (! $student) {
                    throw ValidationException::withMessages(['sis' => 'Estudiante inexistente o sin inscripción activa en esta oferta.']);
                }
                if (in_array((int) $student->id, $existing, true)) {
                    throw ValidationException::withMessages(['sis' => 'Ya existen datos; utiliza una corrección explícita.']);
                }
                $identity = $row['sis'].':'.$row['assessment_key'];
                if (isset($seen[$identity])) {
                    throw ValidationException::withMessages(['assessment_key' => 'Evaluación duplicada para el mismo SIS.']);
                }
                $seen[$identity] = true;
                $data = $this->validateRecord(['assessments' => [$row['assessment_key'] => ['was_taken' => $row['was_taken'] === 'true', 'score' => $row['score'] === '' ? null : $row['score']]],
                    'attended_classes' => $row['attended_classes'] === '' ? null : $row['attended_classes'], 'total_classes' => $row['total_classes'] === '' ? null : $row['total_classes']]);
                $group = $groups[$student->id] ?? ['student_id' => (int) $student->id, 'sis' => $student->sis_code, 'assessments' => [], 'attended_classes' => null, 'total_classes' => null];
                if ($data['total_classes'] !== null) {
                    if ($group['total_classes'] !== null && ((int) $group['total_classes'] !== (int) $data['total_classes'] || (int) $group['attended_classes'] !== (int) $data['attended_classes'])) {
                        throw ValidationException::withMessages(['attendance' => 'Las cantidades de asistencia del mismo estudiante deben coincidir.']);
                    }
                    $group['attended_classes'] = (int) $data['attended_classes'];
                    $group['total_classes'] = (int) $data['total_classes'];
                }
                $group['assessments'] += $data['assessments'];
                $groups[$student->id] = $group;
            } catch (ValidationException $exception) {
                $errors[] = ['row' => $i + 2, 'sis' => $row['sis'] ?? null, 'messages' => $exception->errors()];
            }
        }

        return DB::transaction(function () use ($request, $offering, $groups, $errors, $rows) {
            $this->support->offering($request, $offering, true);
            $preview = AcademicImport::create(['id' => (string) Str::uuid(), 'course_offering_id' => $offering, 'created_by' => $request->user()->id,
                'preview' => ['total_rows' => count($rows), 'valid_records' => array_values($groups), 'errors' => $errors], 'expires_at' => now()->addMinutes(30)]);
            $this->support->audit($request, 'ACADEMIC_IMPORT_PREVIEWED', 'CourseOffering', $offering, null, ['preview_id' => $preview->id, 'total_rows' => count($rows), 'errors' => count($errors)]);

            return $preview;
        });
    }

    public function confirm(Request $request, int $offering, string $previewId): array
    {
        return DB::transaction(function () use ($request, $offering, $previewId) {
            $this->support->offering($request, $offering, true);
            $import = AcademicImport::whereKey($previewId)->where('course_offering_id', $offering)->where('created_by', $request->user()->id)->lockForUpdate()->firstOrFail();
            if ($import->confirmed_at) {
                return $import->result;
            }
            if ($import->expires_at->isPast()) {
                $this->support->conflict('La vista previa venció.');
            }
            if ($import->preview['errors']) {
                throw ValidationException::withMessages(['preview' => 'Corrige las filas con error antes de confirmar.']);
            }
            if (($import->preview['mode'] ?? 'CREATE_ONLY') === 'MERGE') {
                return app(AcademicMergeService::class)->confirm($request, $offering, $import);
            }
            $activeIds = array_map(fn ($s) => (int) $s->id, $this->activeStudents($offering));
            $ids = [];
            foreach ($import->preview['valid_records'] as $data) {
                if (! in_array($data['student_id'], $activeIds, true) || AcademicRecord::where('course_offering_id', $offering)->where('student_id', $data['student_id'])->exists()) {
                    $this->support->conflict('Cambió la inscripción o ya se importaron datos de un estudiante. Genera otro preview.');
                }
                unset($data['sis']);
                $row = AcademicRecord::create($data + ['course_offering_id' => $offering, 'updated_by' => $request->user()->id]);
                $ids[] = $row->id;
                $this->support->audit($request, 'ACADEMIC_RECORD_IMPORTED', 'AcademicRecord', $row->id, null, $row->toArray());
            }
            $result = ['preview_id' => $import->id, 'created_records' => count($ids), 'record_ids' => $ids];
            $import->update(['confirmed_at' => now(), 'result' => $result]);
            $this->support->audit($request, 'ACADEMIC_IMPORT_CONFIRMED', 'CourseOffering', $offering, null, $result);

            return $result;
        });
    }

    public function correct(Request $request, int $offering, int $record): AcademicRecord
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500'],
            'assessments' => ['present', 'array'], 'attended_classes' => ['present', 'nullable'], 'total_classes' => ['present', 'nullable']]);
        $values = $this->validateRecord($input);

        return DB::transaction(function () use ($request, $offering, $record, $input, $values) {
            $this->support->offering($request, $offering, true);
            $row = AcademicRecord::whereKey($record)->where('course_offering_id', $offering)->lockForUpdate()->firstOrFail();
            if ($row->revision !== (int) $input['revision']) {
                $this->support->conflict('Los datos académicos cambiaron.');
            }
            $old = $row->toArray();
            $row->update($values + ['revision' => $row->revision + 1, 'updated_by' => $request->user()->id]);
            $this->support->audit($request, 'ACADEMIC_RECORD_CORRECTED', 'AcademicRecord', $row->id, $old, $row->toArray() + ['correction_reason' => $input['reason']]);

            return $row;
        });
    }
}
