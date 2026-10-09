<?php

namespace App\Services\Subjects;

use App\Http\Requests\Api\V1\Subjects\SaveSubjectRequest;
use App\Models\Career;
use App\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubjectCsvParser
{
    private const HEADERS = ['codigo_materia', 'nombre_materia', 'codigo_carrera'];

    public function report(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'El archivo debe usar UTF-8 válido.']);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $content);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => 'El encabezado debe ser codigo_materia,nombre_materia,codigo_carrera, en ese orden.']);
            }
            $line = 1 + max(1, preg_match_all('/\r\n|\r|\n/', substr($content, 0, ftell($stream))));
            $request = new SaveSubjectRequest;
            $rules = array_intersect_key($request->rules(), array_flip(['code', 'name']));
            $rows = [];
            $names = [];
            while (($start = ftell($stream)) !== false && ($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $raw = substr($content, $start, ftell($stream) - $start);
                $data = array_combine(self::HEADERS, array_map(fn ($i) => (string) ($cells[$i] ?? ''), range(0, 2)));
                $code = strtoupper(trim($data['codigo_materia']));
                $name = trim($data['nombre_materia']);
                $errors = Validator::make(['code' => $code, 'name' => $name], $rules, $request->messages())->errors()->all();
                if (count($cells) !== 3) {
                    $errors[] = 'La fila debe contener exactamente tres columnas.';
                }
                if ($this->hasInvalidQuotes($raw)) {
                    $errors[] = 'La fila contiene comillas CSV sin cerrar o mal ubicadas.';
                }
                if (trim($data['codigo_carrera']) === '') {
                    $errors[] = 'El código de carrera es obligatorio.';
                }
                $names[$code][$name] = true;
                $rows[] = ['row_number' => $line, 'data' => $data, 'status' => 'VALID', 'errors' => $errors];
                $line += max(1, preg_match_all('/\r\n|\r|\n/', $raw));
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo CSV no contiene filas de datos.']);
        }

        // Batch reference lookups and retain legacy code casing without altering the catalogue.
        $codes = array_values(array_unique(array_map(fn ($row) => strtoupper(trim($row['data']['codigo_materia'])), $rows)));
        $careerCodes = array_values(array_unique(array_map(fn ($row) => trim($row['data']['codigo_carrera']), $rows)));
        $subjects = collect();
        foreach (array_chunk($codes, 500) as $chunk) {
            $subjects = $subjects->concat(Subject::with('careers')->whereIn(\Illuminate\Support\Facades\DB::raw('UPPER(code)'), $chunk)->get());
        }
        $subjects = $subjects->groupBy(fn ($subject) => strtoupper($subject->code));
        $careers = collect();
        foreach (array_chunk($careerCodes, 500) as $chunk) {
            $careers = $careers->concat(Career::whereIn('code', $chunk)->get());
        }
        $careers = $careers->keyBy('code');
        $seen = [];
        foreach ($rows as &$row) {
            $code = strtoupper(trim($row['data']['codigo_materia']));
            $name = trim($row['data']['nombre_materia']);
            $careerCode = trim($row['data']['codigo_carrera']);
            if (count($names[$code]) > 1) {
                $row['errors'][] = 'El mismo código tiene nombres diferentes dentro del archivo.';
            }
            $matches = $subjects->get($code);
            $subject = $matches?->first();
            if ($matches && $matches->count() > 1) {
                $row['errors'][] = 'El código coincide con varias materias existentes; requiere revisión.';
            }
            if ($subject && $subject->status !== 'ACTIVE') {
                $row['errors'][] = 'La materia está inactiva; no se reactivará al importar.';
            }
            if ($subject && trim($subject->name) !== $name) {
                $row['errors'][] = 'El nombre no coincide con la materia existente.';
            }
            $career = $careers->get($careerCode);
            if (! $career) {
                $row['errors'][] = 'La carrera no existe.';
            } elseif ($career->status !== 'ACTIVE') {
                $row['errors'][] = 'La carrera está inactiva.';
            }
            $pair = json_encode([$code, $careerCode]);
            if ($row['errors'] !== []) {
                $row['status'] = 'ERROR';
            } elseif (isset($seen[$pair])) {
                $row['status'] = 'OMITTED';
                $row['errors'][] = 'Asociación repetida en el archivo.';
            } elseif ($subject && $subject->careers->contains('id', $career->id)) {
                $row['status'] = 'OMITTED';
                $row['errors'][] = 'La materia ya está asociada a esta carrera.';
            }
            if ($row['status'] !== 'ERROR') {
                $seen[$pair] = true;
            }
        }
        unset($row);
        $counts = array_count_values(array_column($rows, 'status'));

        return ['summary' => ['total' => count($rows), 'valid' => $counts['VALID'] ?? 0, 'invalid' => $counts['ERROR'] ?? 0, 'omitted' => $counts['OMITTED'] ?? 0], 'rows' => $rows];
    }

    private function hasInvalidQuotes(string $record): bool
    {
        $state = 'start';
        for ($i = 0, $length = strlen($record); $i < $length; $i++) {
            $char = $record[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($record[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }
            } elseif ($char === ',') {
                $state = 'start';
            } elseif ($char === "\r" || $char === "\n") {
                continue;
            } elseif ($state === 'start' && $char === '"') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                return true;
            } else {
                $state = 'plain';
            }
        }

        return $state === 'quoted';
    }
}
