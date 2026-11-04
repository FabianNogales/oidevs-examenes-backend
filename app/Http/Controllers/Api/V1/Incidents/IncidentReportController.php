<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\IncidentReportRequest;
use App\Http\Resources\Incidents\IncidentReportResource;
use App\Services\Incidents\IncidentReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function index(IncidentReportRequest $request, IncidentReportService $service)
    {
        $page = $service->paginate($request->user(), $request->validated());

        return IncidentReportResource::collection($page)->additional([
            'message' => $page->total() === 0 ? 'No se encontraron incidencias con los criterios seleccionados.' : 'Incidencias consultadas correctamente.',
        ]);
    }

    public function summary(IncidentReportRequest $request, IncidentReportService $service)
    {
        return response()->json(['data' => $service->summary($request->user(), $request->validated())]);
    }

    public function show(IncidentReportRequest $request, string $incidentId, IncidentReportService $service): IncidentReportResource
    {
        return new IncidentReportResource($service->find($request->user(), $incidentId, $request->validated()));
    }

    public function export(IncidentReportRequest $request, IncidentReportService $service): StreamedResponse
    {
        // Resolve authorization before starting a streamed HTTP response.
        $records = $service->export($request->user(), $request->validated());

        return response()->streamDownload(function () use ($records, $request) {
            $stream = fopen('php://output', 'w');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['Identificador', 'Examen ID', 'Examen', 'Tipo', 'Origen', 'Estado', 'Motivo', 'Descripción',
                    'Fecha UTC', 'Estudiante ID', 'Nombres', 'Apellidos', 'SIS', 'CI', 'Responsable ID', 'Responsable'], ',', '"', '');
                foreach ($records as $record) {
                    $data = (new IncidentReportResource($record))->resolve($request);
                    $cells = [$data['id'], $data['exam_id'], $data['exam']['name'], $data['type'], $data['origin'],
                        $data['status'], $data['reason'], $data['description'], $data['reported_at'], $data['student_id'],
                        $data['student']['first_names'] ?? null, $data['student']['last_names'] ?? null,
                        $data['student']['sis_code'] ?? null, $data['student']['identity_number'] ?? null,
                        $data['reported_by'], $data['responsible']['name'] ?? null];
                    fputcsv($stream, array_map($this->safeCell(...), $cells), ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }, 'ingresos-irregulares.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^(?:\s*[=+@-]|[\t\r\n])/u', $text) ? "'".$text : $text;
    }
}
