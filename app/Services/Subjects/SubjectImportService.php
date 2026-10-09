<?php

namespace App\Services\Subjects;

use App\Models\SubjectImport;
use App\Models\Subject;
use App\Models\Career;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubjectImportService
{
    public function __construct(private readonly SubjectCsvParser $parser, private readonly AuditLogService $audit) {}

    public function preview(UploadedFile $file, int $userId): array
    {
        $report = $this->parser->report($file);
        $expires = now()->utc()->addMinutes(30);
        $previewId = (string) Str::uuid();
        SubjectImport::create([
            'preview_id' => $previewId, 'user_id' => $userId,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'status' => 'PENDING', 'expires_at' => $expires, 'preview_report' => $report,
        ]);

        return ['preview_id' => $previewId, 'expires_at' => $expires->toIso8601ZuluString()] + $report;
    }

    public function confirm(UploadedFile $file, string $previewId, Request $request): array
    {
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($file, $previewId, $request, $hash) {
            $import = SubjectImport::where('preview_id', $previewId)->lockForUpdate()->first();
            abort_if(! $import, 409, 'La vista previa no está disponible. Valida nuevamente el archivo.');
            abort_if($import->user_id !== $request->user()->id, 403, 'La vista previa pertenece a otro administrador.');
            abort_if(! hash_equals($import->file_hash, $hash), 409, 'El archivo no coincide con la vista previa.');
            if ($import->status === 'COMPLETED') {
                return $import->result_report;
            }
            abort_if($import->expires_at->lte(now()), 410, 'La vista previa ha vencido.');
            abort_if($import->status !== 'PENDING', 409, 'La importación no está disponible para confirmar.');
            abort_if(($import->preview_report['summary']['valid'] ?? 0) < 1, 409, 'La vista previa no contiene filas válidas.');
            $import->update(['status' => 'PROCESSING']);
            $rows = $this->parser->report($file)['rows'];
            $groups = [];
            foreach ($rows as $index => $row) {
                if ($row['status'] === 'VALID') {
                    $groups[strtoupper(trim($row['data']['codigo_materia']))][$index] = $row;
                }
            }
            ksort($groups);
            foreach ($groups as $code => $group) {
                try {
                    try {
                        $outcomes = DB::transaction(fn () => $this->importGroup((string) $code, $group, $request));
                    } catch (UniqueConstraintViolationException $exception) {
                        if (! str_contains($exception->getMessage(), 'subjects_code_unique') && ! str_contains($exception->getMessage(), 'subjects.code')) {
                            throw $exception;
                        }
                        // A concurrent creator won; re-read under a fresh savepoint.
                        $outcomes = DB::transaction(fn () => $this->importGroup((string) $code, $group, $request));
                    }
                    foreach ($outcomes as $index => $row) {
                        $rows[$index] = $row;
                    }
                } catch (ValidationException $exception) {
                    foreach ($group as $index => $row) {
                        $rows[$index]['status'] = 'ERROR';
                        $rows[$index]['errors'] = array_merge(...array_values($exception->errors()));
                    }
                }
            }
            $counts = array_count_values(array_column($rows, 'status'));
            $report = ['preview_id' => $previewId, 'summary' => [
                'total' => count($rows), 'imported' => $counts['IMPORTED'] ?? 0,
                'failed' => $counts['ERROR'] ?? 0, 'omitted' => $counts['OMITTED'] ?? 0,
            ], 'rows' => $rows];
            $import->update(['status' => 'COMPLETED', 'result_report' => $report, 'completed_at' => now()->utc()]);

            return $report;
        }, 3);
    }

    private function importGroup(string $code, array $rows, Request $request): array
    {
        $matches = Subject::whereRaw('UPPER(code) = ?', [$code])->orderBy('id')->lockForUpdate()->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['code' => 'El código coincide con varias materias existentes; requiere revisión.']);
        }
        $subject = $matches->first();
        $name = trim(reset($rows)['data']['nombre_materia']);
        if ($subject && ($subject->status !== 'ACTIVE' || trim($subject->name) !== $name)) {
            throw ValidationException::withMessages(['code' => 'La materia está inactiva o su nombre ya no coincide.']);
        }
        $careerCodes = array_map(fn ($row) => trim($row['data']['codigo_carrera']), $rows);
        $careers = collect();
        foreach (array_chunk(array_unique($careerCodes), 500) as $chunk) {
            $careers = $careers->concat(Career::whereIn('code', $chunk)->orderBy('id')->lockForUpdate()->get());
        }
        $careers = $careers->keyBy('code');
        $valid = [];
        foreach ($rows as $index => &$row) {
            $career = $careers->get(trim($row['data']['codigo_carrera']));
            if (! $career || $career->status !== 'ACTIVE') {
                $row['status'] = 'ERROR';
                $row['errors'] = ['La carrera ya no existe o está inactiva.'];
            } elseif ($subject && $subject->careers()->where('careers.id', $career->id)->exists()) {
                $row['status'] = 'OMITTED';
                $row['errors'] = ['La materia ya está asociada a esta carrera.'];
            } else {
                $valid[$index] = $career->id;
            }
        }
        unset($row);
        if ($valid === []) {
            return $rows;
        }
        $creating = $subject === null;
        $old = $creating ? null : $this->snapshot($subject);
        if ($creating) {
            $subject = Subject::create(['code' => $code, 'name' => $name, 'status' => 'ACTIVE']);
        }
        $subject->careers()->syncWithoutDetaching(array_values($valid));
        $this->audit->log($request, $request->user()->id,
            $creating ? 'SUBJECT_CREATED' : 'SUBJECT_UPDATED', Subject::class, $subject->id, $old, $this->snapshot($subject));
        foreach ($valid as $index => $careerId) {
            $rows[$index]['status'] = 'IMPORTED';
            $rows[$index]['subject_id'] = (int) $subject->id;
        }

        return $rows;
    }

    private function snapshot(Subject $subject): array
    {
        return $subject->only(['code', 'name', 'status']) + [
            'career_ids' => $subject->careers()->orderBy('careers.id')->pluck('careers.id')->all(),
        ];
    }
}
