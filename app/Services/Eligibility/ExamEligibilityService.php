<?php

namespace App\Services\Eligibility;

use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Services\Audit\AuditLogService;
use App\Support\Eligibility\EligibilityRules;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExamEligibilityService
{
    public function responsibleExam(Request $request, int $examId): Exam
    {
        abort_unless($request->user()?->isActive(), 403, 'La cuenta no está activa.');
        $exam = Exam::with('courseOffering.teacher')->findOrFail($examId);
        abort_unless((int) $exam->courseOffering?->teacher?->user_id === $request->user()->id, 403,
            'Solo el docente responsable puede gestionar las habilitaciones de este examen.');

        return $exam;
    }

    public function save(ExamEligibility $eligibility, array $data, Request $request): ExamEligibility
    {
        $old = $eligibility->only(['status', 'reason_code', 'reason', 'observations', 'evaluated_by', 'evaluated_at']);
        $eligibility->update(EligibilityRules::values($data) + [
            'evaluated_by' => $request->user()->id,
            'evaluated_at' => now(),
        ]);
        app(AuditLogService::class)->log($request, $request->user()->id, 'EXAM_ELIGIBILITY_UPDATED',
            'ExamEligibility', $eligibility->id, $old,
            $eligibility->only(['exam_id', 'student_id', 'status', 'reason_code', 'reason', 'observations', 'evaluated_by', 'evaluated_at']));

        return $eligibility;
    }

    public function bulk(UploadedFile $file, Exam $exam, Request $request): array
    {
        $raw = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw ValidationException::withMessages(['file' => ['El archivo debe ser CSV UTF-8 válido.']]);
        }

        $handle = fopen($file->getRealPath(), 'rb');
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            $required = ['sis_code', 'status', 'reason_code', 'observations'];
            if (! $header || count($header) !== 4 || array_diff($required, $header)) {
                throw ValidationException::withMessages(['file' => ['Encabezados requeridos: sis_code,status,reason_code,observations.']]);
            }

            $this->validateCsvRecord(preg_replace('/^\xEF\xBB\xBF/', '', substr($raw, 0, ftell($handle))));
            $rows = [];
            $offset = ftell($handle);
            $line = 1 + preg_match_all('/\r\n|\r|\n/', substr($raw, 0, $offset));
            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $nextOffset = ftell($handle);
                $this->validateCsvRecord(substr($raw, $offset, $nextOffset - $offset));
                $rowLine = $line;
                $line += preg_match_all('/\r\n|\r|\n/', substr($raw, $offset, $nextOffset - $offset));
                $offset = $nextOffset;
                if ($fields === [null]) {
                    continue;
                }
                if (count($rows) >= 1000) {
                    throw ValidationException::withMessages(['file' => ['El archivo admite como máximo 1.000 registros.']]);
                }
                $data = count($fields) === count($header) ? array_combine($header, array_map('trim', $fields)) : null;
                $rows[] = ['row' => $rowLine, 'data' => $data];
            }
        } finally {
            fclose($handle);
        }

        if (! $rows) {
            throw ValidationException::withMessages(['file' => ['El archivo no contiene registros.']]);
        }

        $counts = array_count_values(array_map(fn ($row) => (string) ($row['data']['sis_code'] ?? ''), $rows));

        return DB::transaction(function () use ($rows, $counts, $exam, $request) {
            $records = ExamEligibility::with('student')->where('exam_id', $exam->id)->lockForUpdate()->get()
                ->keyBy(fn ($eligibility) => $eligibility->student->sis_code);
            $errors = [];
            $updated = 0;
            foreach ($rows as $row) {
                $data = $row['data'];
                $sis = $data['sis_code'] ?? null;
                $messages = [];
                if ($data === null) {
                    $messages['row'] = ['La cantidad de columnas no coincide con los encabezados.'];
                } else {
                    $validator = Validator::make($data, EligibilityRules::rules($data) + [
                        'sis_code' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
                    ]);
                    $messages = $validator->errors()->toArray();
                    if ($sis !== '' && ($counts[$sis] ?? 0) > 1) {
                        $messages['sis_code'][] = 'El SIS está repetido en el archivo; se rechazan todas sus filas.';
                    }
                    if ($sis !== '' && ! $records->has($sis)) {
                        $messages['sis_code'][] = 'El estudiante no tiene una habilitación asociada a este examen.';
                    }
                }
                if ($messages) {
                    $errors[] = ['row' => $row['row'], 'sis_code' => $sis, 'messages' => $messages];

                    continue;
                }
                $this->save($records->get($sis), $data, $request);
                $updated++;
            }

            return ['total_rows' => count($rows), 'updated_rows' => $updated, 'failed_rows' => count($errors), 'errors' => $errors];
        });
    }

    private function validateCsvRecord(string $record): void
    {
        // Rechazar comillas sin cerrar o caracteres después de una celda entrecomillada.
        $field = '(?:[^",\r\n]*|"(?:[^"]|"")*")';
        if (preg_match('/\A'.$field.'(?:,'.$field.')*(?:\r\n|\r|\n)?\z/', $record) !== 1) {
            throw ValidationException::withMessages(['file' => ['El archivo contiene una fila con formato CSV inválido.']]);
        }
    }
}
