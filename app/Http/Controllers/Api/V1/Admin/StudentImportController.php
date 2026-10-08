<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogService;
use App\Services\Students\StudentCsvImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentImportController extends Controller
{
    private AuditLogService $auditLogService;

    public function __construct(
        AuditLogService $auditLogService
    ) {
        $this->auditLogService = $auditLogService;
    }

    public function preview(
        Request $request,
        StudentCsvImportService $service
    ) {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
            ],
        ]);

        $result = $service->validate(
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'Vista previa generada correctamente.',
            'data' => $result,
        ]);
    }

    public function confirm(
        Request $request,
        StudentCsvImportService $service
    ) {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
            ],
        ]);

        $result = $service->import(
            $request->file('file'),
            $request->user(),
            $request
        );

        $result['audit_status'] = 'RECORDED';
        $result['warnings'] = [];

        try {
            $this->auditLogService->log(
                $request,
                $request->user()?->id,
                'STUDENT_IMPORT',
                'STUDENT_IMPORT',
                null,
                null,
                [
                    'file_name' => $request->file('file')->getClientOriginalName(),
                    'total_rows' => $result['total_rows'],
                    'valid_rows' => $result['valid_rows'],
                    'error_rows' => $result['error_rows'],
                    'imported_rows' => $result['imported_rows'],
                    'skipped_rows' => $result['skipped_rows'],
                    'failed_rows' => $result['failed_rows'],
                ]
            );
        } catch (\Throwable $exception) {
            // Las filas ya confirmadas mantienen su resultado y su auditoría individual.
            $result['audit_status'] = 'FAILED';
            $result['warnings'][] = 'No se pudo registrar el resumen de auditoría. Los resultados de las filas se conservan.';
            Log::error('Falló el resumen de auditoría de importación de estudiantes.', [
                'exception_type' => $exception::class,
                'imported_rows' => $result['imported_rows'],
                'skipped_rows' => $result['skipped_rows'],
                'failed_rows' => $result['failed_rows'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['warnings'] !== []
                ? 'Importación procesada con una advertencia de auditoría.'
                : ($result['failed_rows'] > 0
                    ? 'Importación procesada con filas fallidas.'
                    : 'Importación procesada correctamente.'),
            'data' => $result,
        ]);
    }
}
