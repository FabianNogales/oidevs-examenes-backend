<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class HistoricalPhotoService
{
    public function save(int $studentId, UploadedFile $photo, int $revision, bool $correction, Request $request): Student
    {
        $path = null;
        try {
            return DB::transaction(function () use ($studentId, $photo, $revision, $correction, $request, &$path) {
                $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
                $current = (int) $student->historical_photo_revision;
                if ($current !== $revision) {
                    $this->conflict('HISTORICAL_PHOTO_CONFLICT', 'La fotografía institucional cambió. Recarga el estudiante.', $current);
                }
                if ($student->historical_photo && ! $correction) {
                    $this->conflict('HISTORICAL_PHOTO_EXISTS', 'La fotografía institucional ya existe. Utiliza la acción de corrección.', $current);
                }
                if (! $student->historical_photo && $correction) {
                    $this->conflict('HISTORICAL_PHOTO_MISSING', 'No existe una fotografía institucional para corregir.', $current);
                }
                $stored = $photo->store('institutional-photos', 'local');
                if (! $stored) {
                    throw new RuntimeException('No se pudo guardar la fotografía institucional.');
                }
                $path = $stored;
                $before = ['historical_photo' => $student->historical_photo, 'revision' => $current];
                $student->forceFill([
                    'historical_photo' => $path,
                    'historical_photo_revision' => $current + 1,
                    'historical_photo_updated_at' => now()->format('Y-m-d H:i:sP'),
                    'historical_photo_updated_by' => $request->user()->id,
                ])->save();
                app(AuditLogService::class)->log($request, $request->user()->id,
                    $correction ? 'STUDENT_INSTITUTIONAL_PHOTO_CORRECTED' : 'STUDENT_INSTITUTIONAL_PHOTO_CREATED',
                    Student::class, $student->id, $before,
                    ['historical_photo' => $path, 'revision' => $current + 1, 'reason' => $correction ? $request->validated('reason') : null]);

                // Corrections retain the previous private file and audit reference.
                return $student->refresh()->load(['user', 'career']);
            });
        } catch (Throwable $error) {
            if ($path) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (Throwable) {
                    Log::warning('Institutional photo rollback cleanup failed.');
                }
            }
            throw $error;
        }
    }

    private function conflict(string $code, string $message, int $revision): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message, 'current_revision' => $revision], 409));
    }
}
