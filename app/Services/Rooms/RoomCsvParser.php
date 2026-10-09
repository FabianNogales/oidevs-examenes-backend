<?php

namespace App\Services\Rooms;

use App\Http\Requests\Api\V1\Rooms\SaveRoomRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoomCsvParser
{
    private const HEADERS = ['codigo', 'nombre', 'descripcion', 'capacidad', 'piso'];

    private const FIELDS = ['code', 'name', 'description', 'capacity', 'floor'];

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
            if ($header === false || array_map(fn ($value) => mb_strtolower(trim($value ?? '')), $header) !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => 'Los encabezados deben ser codigo,nombre,descripcion,capacidad,piso, en ese orden.']);
            }

            $request = new SaveRoomRequest;
            $rules = array_intersect_key($request->rules(), array_flip(self::FIELDS));
            // CSV capacities are strings, but must represent whole positive numbers.
            $rules['capacity'][] = 'regex:/^[0-9]+$/';
            $rows = [];
            $line = 2;
            while (! feof($stream)) {
                $start = ftell($stream);
                $cells = fgetcsv($stream, 0, ',', '"', '');
                if ($cells === false) {
                    break;
                }
                $end = ftell($stream);
                $raw = substr($content, $start, $end - $start);
                $data = array_combine(self::FIELDS, array_map(fn ($index) => trim((string) ($cells[$index] ?? ''), ' '), range(0, 4)));
                $validationData = array_map(fn ($value) => $value === '' ? null : $value, $data);
                $validator = Validator::make($validationData, $rules, $request->messages());
                $errors = $validator->errors()->all();
                if (count($cells) !== 5) {
                    $errors[] = 'La fila debe contener exactamente cinco columnas.';
                }
                if ($this->hasInvalidQuotes($raw)) {
                    $errors[] = 'La fila contiene comillas CSV sin cerrar o mal ubicadas.';
                }
                $rows[] = ['row' => $line, 'data' => $data, 'valid' => $errors === [], 'errors' => $errors];
                $line += max(1, preg_match_all('/\r\n|\r|\n/', $raw));
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo CSV no contiene filas de datos.']);
        }
        foreach (['code', 'name'] as $field) {
            $counts = array_count_values(array_column(array_column($rows, 'data'), $field));
            foreach ($rows as &$row) {
                if ($row['data'][$field] !== '' && $counts[$row['data'][$field]] > 1) {
                    $row['errors'][] = $field === 'code' ? 'El código está duplicado dentro del archivo.' : 'El nombre está duplicado dentro del archivo.';
                    $row['valid'] = false;
                }
            }
            unset($row);
        }
        $valid = count(array_filter($rows, fn ($row) => $row['valid']));

        return ['total_rows' => count($rows), 'valid_rows' => $valid, 'error_rows' => count($rows) - $valid, 'errors' => [], 'rows' => $rows];
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
