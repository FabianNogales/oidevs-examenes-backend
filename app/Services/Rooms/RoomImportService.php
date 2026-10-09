<?php

namespace App\Services\Rooms;

use App\Models\RoomImport;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomImportService
{
    public function __construct(private readonly RoomCsvParser $parser, private readonly RoomService $rooms) {}

    public function preview(UploadedFile $file, int $userId): array
    {
        $report = $this->parser->report($file);
        $expires = now('America/La_Paz')->addMinutes(30);
        $previewId = (string) Str::uuid();
        RoomImport::create([
            'preview_id' => $previewId,
            'user_id' => $userId,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'status' => 'PENDING',
            'expires_at' => $expires->copy()->utc(),
            'preview_report' => $report,
        ]);

        return ['preview_id' => $previewId, 'expires_at' => $expires->toIso8601String()] + $report;
    }

    public function confirm(UploadedFile $file, string $previewId, Request $request): array
    {
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($file, $previewId, $request, $hash) {
            // Serialize confirmations across server processes; commit rooms and report together.
            $import = RoomImport::where('preview_id', $previewId)->lockForUpdate()->first();
            abort_if(! $import, 409, 'La vista previa no está disponible. Valida nuevamente el archivo.');
            abort_if($import->user_id !== $request->user()->id, 403, 'La vista previa pertenece a otro administrador.');
            abort_if(! hash_equals($import->file_hash, $hash), 409, 'El archivo no coincide con la vista previa.');

            if ($import->status === 'COMPLETED') {
                return $import->result_report;
            }
            abort_if($import->expires_at->lte(now()), 410, 'La vista previa ha vencido. Valida nuevamente el archivo.');
            abort_if($import->status !== 'PENDING', 409, 'La importación no está disponible para confirmar.');
            abort_if(! empty($import->preview_report['errors']), 409, 'La vista previa contiene errores generales.');

            // PROCESSING is never committed alone: interruption rolls back to PENDING.
            $import->update(['status' => 'PROCESSING']);
            $report = $this->parser->report($file);
            foreach ($report['rows'] as &$row) {
                if (! $row['valid']) {
                    continue;
                }
                $data = array_map(fn ($value) => $value === '' ? null : $value, $row['data']);
                $data['capacity'] = $data['capacity'] === null ? null : (int) $data['capacity'];
                try {
                    // Nested transaction/savepoint lets a code conflict reject only this row.
                    $this->rooms->save($data, $request);
                } catch (ValidationException $exception) {
                    $row['valid'] = false;
                    $row['errors'] = array_merge($row['errors'], array_merge(...array_values($exception->errors())));
                }
            }
            unset($row);
            $report['valid_rows'] = count(array_filter($report['rows'], fn ($row) => $row['valid']));
            $report['error_rows'] = $report['total_rows'] - $report['valid_rows'];
            $report['imported_rows'] = $report['valid_rows'];
            $report['failed_rows'] = $report['error_rows'];
            $import->update(['status' => 'COMPLETED', 'result_report' => $report, 'completed_at' => now()->utc()]);

            return $report;
        });
    }
}
