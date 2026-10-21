<?php

namespace App\Services\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\CourseOfferingImport;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use App\Services\AcademicTerms\AcademicTermOfferingGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseOfferingImportService
{
    public function __construct(private readonly CourseOfferingCsvParser $parser, private readonly AcademicTermOfferingGuard $periods,
        private readonly AcademicTermIntegrity $integrity, private readonly CourseOfferingService $offerings) {}

    public function confirm(UploadedFile $file, int $academicTermId, string $previewId, Request $request): array
    {
        try {
            // Use the same lock order as manual assignment and period closure.
            return $this->integrity->transaction(function () use ($file, $academicTermId, $previewId, $request) {
                $import = CourseOfferingImport::where('preview_id', $previewId)->where('user_id', $request->user()->id)->lockForUpdate()->first();
                if (! $import) {
                    $this->reject(404, 'IMPORT_NOT_FOUND', 'La previsualización no existe o no está disponible.');
                }
                if ((int) ($import->preview_report['academic_term_id'] ?? 0) !== $academicTermId
                    || ! hash_equals($import->file_hash, hash_file('sha256', $file->getRealPath()))) {
                    $this->reject(409, 'IMPORT_PREVIEW_MISMATCH', 'El archivo y el periodo deben coincidir con la previsualización.');
                }
                if ($import->status === 'COMPLETED') {
                    return $import->result_report;
                }
                if ($import->expires_at->lessThanOrEqualTo(now())) {
                    $this->reject(410, 'IMPORT_PREVIEW_EXPIRED', 'La previsualización ha vencido. Vuelve a validar el archivo.');
                }
                if ($import->status !== 'PENDING') {
                    $this->reject(409, 'IMPORT_IN_PROGRESS', 'La importación todavía no está disponible.');
                }
                if (($import->preview_report['summary']['valid'] ?? 0) < 1) {
                    $this->reject(422, 'IMPORT_NO_VALID_ROWS', 'La previsualización no contiene filas listas para importar.');
                }

                return $this->periods->write($academicTermId, function () use ($file, $academicTermId, $previewId, $request, $import) {
                    if (AcademicTerm::where('status', 'ACTIVE')->limit(2)->get()->count() > 1) {
                        $this->reject(409, 'ACADEMIC_TERM_STATE_INCONSISTENT', 'Existen varios periodos activos. Revisa los periodos antes de importar.', 'academic_term_id');
                    }
                    $import->update(['status' => 'PROCESSING']);
                    $current = $this->parser->report($file, $academicTermId);
                    $rows = $import->preview_report['rows'];
                    foreach ($rows as $index => &$row) {
                        if ($row['status'] !== 'VALID') {
                            continue;
                        }
                        $row = $current['rows'][$index];
                        if ($row['status'] !== 'VALID') {
                            continue;
                        }
                        try {
                            // A savepoint rolls back both creation and audit for a failed row.
                            $offering = DB::transaction(function () use ($row, $academicTermId, $request, $previewId) {
                                $teacher = Teacher::whereHas('user', fn ($user) => $user->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($row['data']['correo_docente']))]))->firstOrFail();
                                $subject = Subject::whereRaw('UPPER(code) = ?', [strtoupper(trim($row['data']['codigo_materia']))])->firstOrFail();

                                return $this->offerings->bulk(['teacher_id' => $teacher->id, 'academic_term_id' => $academicTermId, 'subject_ids' => [$subject->id]], $request, $previewId)->first();
                            });
                            $row['status'] = 'IMPORTED';
                            $row['course_offering_id'] = (int) $offering->id;
                        } catch (ModelNotFoundException $exception) {
                            $row['status'] = 'ERROR';
                            $row['errors'] = ['El docente o la materia dejó de estar disponible.'];
                        } catch (HttpResponseException $exception) {
                            $response = $exception->getResponse();
                            $body = json_decode($response->getContent(), true);
                            if (! in_array($body['code'] ?? '', ['COURSE_OFFERING_DUPLICATE', 'COURSE_OFFERING_TEACHER_INACTIVE', 'COURSE_OFFERING_SUBJECT_INACTIVE'], true)) {
                                throw $exception;
                            }
                            $row['status'] = ($body['code'] ?? '') === 'COURSE_OFFERING_DUPLICATE' ? 'OMITTED' : 'ERROR';
                            $row['errors'] = [$body['message']];
                        }
                    }
                    unset($row);
                    $counts = array_count_values(array_column($rows, 'status'));
                    $report = ['preview_id' => $previewId, 'academic_term_id' => $academicTermId,
                        'summary' => ['total' => count($rows), 'imported' => $counts['IMPORTED'] ?? 0, 'failed' => $counts['ERROR'] ?? 0, 'omitted' => $counts['OMITTED'] ?? 0], 'rows' => $rows];
                    $import->update(['status' => 'COMPLETED', 'result_report' => $report, 'completed_at' => now()->utc()]);

                    return $report;
                });
            });
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->reject(500, 'INTERNAL_ERROR', 'No se pudo confirmar la importación. Puedes reintentar con la misma previsualización.');
        }
    }

    private function reject(int $status, string $code, string $message, string $field = 'preview_id'): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code, 'errors' => [$field => [$message]]], $status));
    }

    public function preview(UploadedFile $file, int $academicTermId, int $userId): array
    {
        return $this->periods->write($academicTermId, function () use ($file, $academicTermId, $userId) {
            if (AcademicTerm::where('status', 'ACTIVE')->limit(2)->get()->count() > 1) {
                throw new HttpResponseException(response()->json(['message' => 'Existen varios periodos activos.',
                    'code' => 'ACADEMIC_TERM_STATE_INCONSISTENT', 'errors' => ['academic_term_id' => ['Revisa los periodos antes de importar.']]], 409));
            }
            $report = $this->parser->report($file, $academicTermId);
            $expires = now()->utc()->addMinutes(30);
            $previewId = (string) Str::uuid();
            $report = ['academic_term_id' => $academicTermId] + $report;
            CourseOfferingImport::create(['preview_id' => $previewId, 'user_id' => $userId,
                'file_hash' => hash_file('sha256', $file->getRealPath()), 'status' => 'PENDING',
                'expires_at' => $expires, 'preview_report' => $report]);

            return ['preview_id' => $previewId, 'expires_at' => $expires->toIso8601ZuluString()] + $report;
        });
    }
}
