<?php

namespace App\Services\Incidents;

use App\Http\Resources\Incidents\IncidentResource;
use App\Services\Reports\TextReportPdf;
use Illuminate\Http\Request;

class ExamIncidentExport
{
    public function render(array $snapshot, string $format, Request $request): string
    {
        $records = $snapshot['records']->map(fn ($record) => (new IncidentResource($record))->resolve($request));
        $report = $snapshot['report'];
        $exam = $snapshot['exam'];
        $filters = json_encode($report['filters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($format === 'pdf') {
            $lines = ['Examen #'.$exam['id'].': '.$exam['name'], 'Materia: '.$exam['subject_name'],
                'Generado: '.$report['generated_at'], 'Zona de filtros: America/La_Paz | Alcance: EXAM_INCIDENTS',
                'Filtros: '.$filters, 'Total de incidencias: '.$records->count(), ''];
            foreach ($records as $record) {
                $student = $record['student'];
                $lines[] = '#'.$record['id'].' | '.$record['type'].' | '.$record['origin'].' | '.$record['status'];
                $lines[] = 'Fecha: '.$record['created_at'].' | Responsable: '.($record['reported_by']['name'] ?? 'No disponible');
                $lines[] = $student === null ? 'Estudiante: sin identificar' : 'Estudiante #'.$student['id'].': '.$student['first_names'].' '.$student['last_names'];
                if ($student !== null) {
                    $lines[] = 'SIS: '.$student['sis_code'].' | CI: '.($student['identity_number'] ?? 'No disponible');
                }
                $lines[] = 'Motivo: '.($record['reason'] ?? $record['description']);
                $lines[] = 'Descripción: '.$record['description'];
                $lines[] = str_repeat('-', 94);
            }
            if ($records->isEmpty()) {
                $lines[] = 'No se encontraron incidencias con los criterios seleccionados.';
            }

            return app(TextReportPdf::class)->render('Reporte de ingresos irregulares', $lines);
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $columns = ['Identificador', 'Examen ID', 'Examen', 'Materia', 'Tipo', 'Origen', 'Estado', 'Motivo', 'Descripción',
                'Fecha UTC', 'Estudiante ID', 'Nombres', 'Apellidos', 'SIS', 'CI', 'Responsable ID', 'Responsable',
                'Generado UTC', 'Zona de filtros', 'Alcance', 'Filtros'];
            fputcsv($stream, $columns, ',', '"', '');
            foreach ($records as $record) {
                $student = $record['student'];
                $responsible = $record['reported_by'];
                $cells = [$record['id'], $exam['id'], $exam['name'], $exam['subject_name'], $record['type'], $record['origin'],
                    $record['status'], $record['reason'] ?? $record['description'], $record['description'], $record['created_at'],
                    $student['id'] ?? null, $student['first_names'] ?? null, $student['last_names'] ?? null,
                    $student['sis_code'] ?? null, $student['identity_number'] ?? null, $responsible['id'] ?? null, $responsible['name'] ?? null,
                    $report['generated_at'], $report['timezone'], $report['scope'], $filters];
                fputcsv($stream, array_map($this->safeCell(...), $cells), ',', '"', '');
            }
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^(?:[\s\p{C}]*[=+@-]|[\t\r\n])/u', $text) ? "'".$text : $text;
    }
}
