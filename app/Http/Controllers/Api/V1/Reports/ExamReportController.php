<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExamReportRequest;
use App\Services\Reports\ExamReportCatalog;
use App\Services\Reports\ExamReportExport;
use App\Services\Reports\ExamReportService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExamReportController extends Controller
{
    private function handle(callable $operation)
    {
        try {
            return $operation()->header('Cache-Control', 'private, no-store');
        } catch (ModelNotFoundException|ValidationException|HttpResponseException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Exam report failed.', ['exception_type' => $exception::class]);

            return response()->json(['message' => 'No se pudo recuperar el reporte. Intenta nuevamente.',
                'code' => 'EXAM_REPORT_UNAVAILABLE'], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function catalog(ExamReportRequest $request, ExamReportCatalog $service)
    {
        return $this->handle(fn () => response()->json($service->consult($request->user(), $request->validated())));
    }

    public function show(ExamReportRequest $request, int $examId, string $type, ExamReportService $service)
    {
        return $this->handle(fn () => response()->json($service->page($examId, $type, $request->user(), $request->validated())));
    }

    public function export(ExamReportRequest $request, int $examId, string $type, ExamReportService $service, ExamReportExport $export)
    {
        return $this->handle(function () use ($request, $examId, $type, $service, $export) {
            $snapshot = $service->snapshot($examId, $type, $request->user(), $request->validated());
            $format = $request->validated('format');
            $content = $export->render($snapshot, $format);

            return response($content, 200, [
                'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="reporte-examen-'.$examId.'.'.$format.'"',
                'X-Report-Id' => $snapshot['report']['id'], 'X-Report-Generated-At' => $snapshot['report']['generated_at'],
                'Access-Control-Expose-Headers' => 'X-Report-Id, X-Report-Generated-At, Content-Disposition',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
