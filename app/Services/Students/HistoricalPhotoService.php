<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Services\Images\DatabaseImageService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class HistoricalPhotoService
{
    public function save(int $studentId, UploadedFile $photo, int $revision, bool $correction, Request $request): Student
    {
        return DB::transaction(function () use ($studentId, $photo, $revision, $correction, $request) {
            $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
            $current = (int) $student->historical_photo_revision;
            if ($current !== $revision) {
                $this->conflict('HISTORICAL_PHOTO_CONFLICT', 'La fotografía institucional cambió. Recarga el estudiante.', $current);
            }
            if (($student->historical_image_id || $student->historical_photo) && ! $correction) {
                $this->conflict('HISTORICAL_PHOTO_EXISTS', 'La fotografía institucional ya existe. Utiliza la acción de corrección.', $current);
            }
            if (! $student->historical_image_id && ! $student->historical_photo && $correction) {
                $this->conflict('HISTORICAL_PHOTO_MISSING', 'No existe una fotografía institucional para corregir.', $current);
            }
            $id = app(DatabaseImageService::class)->store($photo, 'historical', $student->id, $request->user()->id);
            $before = ['historical_photo' => $student->historical_photo, 'historical_image_id' => $student->historical_image_id, 'revision' => $current];
            $student->forceFill([
                'historical_photo' => null, 'historical_image_id' => $id,
                'historical_photo_revision' => $current + 1,
                'historical_photo_updated_at' => now()->format('Y-m-d H:i:sP'),
                'historical_photo_updated_by' => $request->user()->id,
            ])->save();
            app(AuditLogService::class)->log($request, $request->user()->id,
                $correction ? 'STUDENT_INSTITUTIONAL_PHOTO_CORRECTED' : 'STUDENT_INSTITUTIONAL_PHOTO_CREATED',
                Student::class, $student->id, $before,
                ['historical_photo' => null, 'historical_image_id' => $id, 'revision' => $current + 1, 'reason' => $correction ? $request->validated('reason') : null]);

            // Retain previous owned bytes and audit reference for institutional corrections.
            return $student->refresh()->load(['user', 'career']);
        });
    }

    private function conflict(string $code, string $message, int $revision): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message, 'current_revision' => $revision], 409));
    }
}
