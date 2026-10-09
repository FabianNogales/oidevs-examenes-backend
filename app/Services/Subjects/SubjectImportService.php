<?php

namespace App\Services\Subjects;

use App\Models\SubjectImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SubjectImportService
{
    public function __construct(private readonly SubjectCsvParser $parser) {}

    public function preview(UploadedFile $file, int $userId): array
    {
        $report = $this->parser->report($file);
        $expires = now()->utc()->addMinutes(30);
        $previewId = (string) Str::uuid();
        SubjectImport::create([
            'preview_id' => $previewId, 'user_id' => $userId,
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'status' => 'PENDING', 'expires_at' => $expires, 'preview_report' => $report,
        ]);

        return ['preview_id' => $previewId, 'expires_at' => $expires->toIso8601ZuluString()] + $report;
    }
}
