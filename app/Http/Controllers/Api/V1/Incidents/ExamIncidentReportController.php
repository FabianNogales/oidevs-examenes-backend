<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\ExportExamIncidentsRequest;
use App\Http\Requests\Incidents\IncidentExamCatalogRequest;
use App\Services\Incidents\ExamIncidentExport;
use App\Services\Incidents\IncidentExamCatalog;
use App\Services\Incidents\IncidentQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExamIncidentReportController extends Controller
{
    public function exams(IncidentExamCatalogRequest $request, IncidentExamCatalog $service): JsonResponse
    {
        return response()->json($service->consult($request->user(), $request->validated()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(ExportExamIncidentsRequest $request, int $examId, IncidentQueryService $service, ExamIncidentExport $export): Response
    {
        try {
            $snapshot = $service->exportData($examId, $request->user(), $request->validated());
            $format = $request->validated('format');
            // Finish reading and rendering before sending success headers or any file bytes.
            $content = $export->render($snapshot, $format, $request);

            return response($content, 200, [
                'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="incidencias-examen-'.$examId.'.'.$format.'"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (ModelNotFoundException|ValidationException|HttpExceptionInterface|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Incident export failed.', ['exception_type' => $exception::class]);

            return response()->json(['message' => 'No se pudo generar el reporte. Intenta nuevamente.',
                'code' => 'INCIDENT_EXPORT_UNAVAILABLE'], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
