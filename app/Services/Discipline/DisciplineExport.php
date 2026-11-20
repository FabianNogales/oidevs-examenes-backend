<?php

namespace App\Services\Discipline;

use App\Services\Reports\TextReportPdf;
use Illuminate\Support\Collection;

class DisciplineExport
{
    public function render(array $exam, Collection $records, string $format): string
    {
        $rows = $records->map(fn ($record) => [$record['id'], $record['kind'], $record['type'], $record['description'], $record['observations'],
            trim(($record['student']['first_names'] ?? '').' '.($record['student']['last_names'] ?? '')) ?: 'Sin identificar',
            $record['student']['sis_code'] ?? '', $record['status'], $record['occurred_at'], $record['reported_by']['name'] ?? '',
            $record['measure_applied'] ? 'Expulsado' : 'Solo registro', $record['measure']['reason'] ?? '']);
        $headers = ['Caso', 'Clase', 'Motivo', 'Descripción', 'Observaciones', 'Estudiante', 'SIS', 'Estado', 'Fecha', 'Responsable', 'Medida', 'Justificación'];
        if ($format === 'pdf') {
            $lines = ['Examen: '.$exam['name'], 'Materia: '.$exam['subject_name'], 'Total de casos: '.$rows->count(), ''];
            foreach ($rows as $row) {
                foreach ($headers as $index => $label) $lines[] = $label.': '.$row[$index];
                $lines[] = str_repeat('-', 90);
            }
            return app(TextReportPdf::class)->render('Incidencias e infracciones', $lines);
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers, ',', '"', '');
            foreach ($rows as $row) fputcsv($stream, array_map(fn ($value) => preg_match('/^(?:[\s\p{C}]*[=+@-]|[\t\r\n])/u', (string) $value) ? "'".$value : (string) $value, $row), ',', '"', '');
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }
}
