<?php

namespace App\Services\Reports;

use App\Enums\ExamReportType;

class ExamReportExport
{
    public function render(array $snapshot, string $format): string
    {
        $exam = $snapshot['exam'];
        $report = $snapshot['report'];
        if ($format === 'pdf') {
            $lines = ['Reporte: '.$report['id'], 'Examen #'.$exam['id'].': '.$exam['name'],
                'Materia: '.$exam['subject']['name'].' | Periodo: '.$exam['academic_term']['name'],
                'Generado: '.$report['generated_at'], 'Alcance: examen | Padrón: inscripciones activas',
                'Participantes: '.$report['total_participants'].' | Registros: '.$report['total_records'], ''];
            foreach ($snapshot['rows'] as $row) {
                $student = $row['student'];
                $lines[] = '#'.$row['source_id'].' | '.$row['record_type'].' | '.$row['state'];
                $lines[] = $student === null ? 'Estudiante: sin identificar' : 'Estudiante #'.$student['id'].': '.$student['first_names'].' '.$student['last_names'];
                if ($student !== null) {
                    $lines[] = 'SIS: '.$student['sis_code'].' | CI: '.($student['identity_number'] ?? 'No disponible');
                }
                if ($row['occurred_at'] !== null) {
                    $lines[] = 'Fecha: '.$row['occurred_at'].' | Responsable: '.$row['recorded_by']['name'];
                }
                foreach (['category' => 'Categoría', 'method' => 'Método', 'origin' => 'Origen',
                    'related_infraction_id' => 'Infracción relacionada', 'description' => 'Descripción'] as $key => $label) {
                    if ($row[$key] !== null) {
                        $lines[] = $label.': '.$row[$key];
                    }
                }
                $lines[] = str_repeat('-', 94);
            }
            if ($snapshot['rows'] === []) {
                $lines[] = 'No se encontraron registros con los criterios seleccionados.';
            }

            return app(TextReportPdf::class)->render(ExamReportType::from($report['type'])->label(), $lines);
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $this->csv($stream, ['Reporte ID', 'Generado UTC', 'Tipo de reporte', 'Alcance', 'Examen ID', 'Examen', 'Materia',
                'Periodo', 'Padrón', 'Participantes', 'Total de registros']);
            $this->csv($stream, [$report['id'], $report['generated_at'], $report['type'], $report['scope'], $exam['id'], $exam['name'],
                $exam['subject']['name'], $exam['academic_term']['name'], $report['roster_scope'], $report['total_participants'], $report['total_records']]);
            $this->csv($stream, []);
            $this->csv($stream, ['Origen ID', 'Clase', 'Examen ID', 'Estudiante ID', 'Nombres', 'Apellidos', 'SIS', 'CI', 'Estado',
                'Categoría', 'Descripción', 'Fecha UTC', 'Método', 'Origen', 'Responsable ID', 'Responsable', 'Infracción relacionada ID']);
            foreach ($snapshot['rows'] as $row) {
                $student = $row['student'];
                $actor = $row['recorded_by'];
                $this->csv($stream, [$row['source_id'], $row['record_type'], $row['exam_id'], $student['id'] ?? null,
                    $student['first_names'] ?? null, $student['last_names'] ?? null, $student['sis_code'] ?? null, $student['identity_number'] ?? null,
                    $row['state'], $row['category'], $row['description'], $row['occurred_at'], $row['method'], $row['origin'],
                    $actor['id'] ?? null, $actor['name'] ?? null, $row['related_infraction_id']]);
            }
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function csv($stream, array $cells): void
    {
        $cells = array_map(function ($cell) {
            $text = (string) ($cell ?? '');

            return preg_match('/^(?:[\s\p{C}]*[=+@-]|[\t\r\n])/u', $text) ? "'".$text : $text;
        }, $cells);
        if (fputcsv($stream, $cells, ',', '"', '') === false) {
            throw new \RuntimeException('Could not render CSV report.');
        }
    }
}
