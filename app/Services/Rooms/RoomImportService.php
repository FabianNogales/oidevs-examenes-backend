<?php

namespace App\Services\Rooms;

use App\Models\RoomImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class RoomImportService
{
    public function __construct(private readonly RoomCsvParser $parser) {}

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
}
