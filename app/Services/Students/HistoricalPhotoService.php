<?php

namespace App\Services\Students;

use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;
use App\Rules\SafeRasterImage;
use App\Services\Audit\AuditLogService;
use App\Services\Images\DatabaseImageService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HistoricalPhotoService
{
    public function requiredFor(User $user): bool
    {
        // Staff with a linked student record keep their existing access.
        return $user->hasRole(RoleName::ESTUDIANTE)
            && ! $user->activeRoles()->where('name', '!=', RoleName::ESTUDIANTE->value)->exists()
            && $user->student && ! $this->isValid($user->student);
    }

    public function isValid(Student $student): bool
    {
        if (! $student->historical_image_id && ! $student->historical_photo) {
            return false;
        }

        try {
            if ($student->historical_image_id) {
                app(DatabaseImageService::class)->read($student->historical_image_id, 'historical', $student->id);
            } else {
                $this->importFile($student->historical_photo);
            }

            return true;
        } catch (NotFoundHttpException|ValidationException) {
            return false;
        } catch (RuntimeException $error) {
            if ($error::class !== RuntimeException::class) {
                throw $error;
            }

            return false;
        }
    }

    public function register(Request $request): Student
    {
        $user = $request->user();
        abort_unless($user->isActive() && ! $user->must_change_password && $this->requiredFor($user), 403);

        return $this->save($user->student?->id ?? 0, $request->file('photo'), null, false, $request, 'STUDENT_UPLOAD');
    }

    /** Only existing, private institutional files can be referenced by CSV. */
    public function importFile(string $path): UploadedFile
    {
        app(DatabaseImageService::class)->read(null, 'historical', 0, $path);
        $file = new UploadedFile(Storage::disk('local')->path($path), basename($path), null, null, true);
        Validator::make(['photo' => $file], ['photo' => ['required', 'file', 'max:5120', new SafeRasterImage]])->validate();

        return $file;
    }

    public function save(int $studentId, UploadedFile $photo, ?int $revision, bool $correction, Request $request, string $source = 'STAFF_UPLOAD'): Student
    {
        return DB::transaction(function () use ($studentId, $photo, $revision, $correction, $request, $source) {
            $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
            $self = $source === 'STUDENT_UPLOAD';
            if ($self) {
                $owner = User::whereKey($student->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($owner->id === $request->user()->id && $owner->isActive() && ! $owner->must_change_password, 403);
            }
            $current = (int) $student->historical_photo_revision;
            if (! $self && $current !== $revision) {
                $this->conflict('HISTORICAL_PHOTO_CONFLICT', 'La fotografía institucional cambió. Recarga el estudiante.', $current);
            }
            if (($self ? $this->isValid($student) : ($student->historical_image_id || $student->historical_photo)) && ! $correction) {
                $this->conflict('HISTORICAL_PHOTO_EXISTS', 'La fotografía institucional ya existe. Utiliza la acción de corrección.', $current);
            }
            if (! $student->historical_image_id && ! $student->historical_photo && $correction) {
                $this->conflict('HISTORICAL_PHOTO_MISSING', 'No existe una fotografía institucional para corregir.', $current);
            }
            $id = app(DatabaseImageService::class)->store($photo, 'historical', $student->id, $request->user()->id);
            $before = ['historical_photo' => $student->historical_photo, 'historical_image_id' => $student->historical_image_id, 'revision' => $current, 'source' => $student->historical_photo_source];
            $student->forceFill([
                'historical_photo' => null, 'historical_image_id' => $id,
                'historical_photo_revision' => $current + 1,
                'historical_photo_updated_at' => now()->format('Y-m-d H:i:sP'),
                'historical_photo_updated_by' => $request->user()->id,
                'historical_photo_source' => $source,
            ])->save();
            if ($self && ! $owner->profile_image_id && ! $owner->profile_photo) {
                // Separate purpose-owned records are required by the existing image reader.
                // Reuse profile storage/audit so later profile replacement cannot delete history.
                app(StudentProfileService::class)->updateProfilePhoto($owner, $photo, $request->ip(), $request->userAgent());
            }
            app(AuditLogService::class)->log($request, $request->user()->id,
                $self ? 'STUDENT_HISTORICAL_PHOTO_REGISTERED' : ($correction ? 'STUDENT_INSTITUTIONAL_PHOTO_CORRECTED' : 'STUDENT_INSTITUTIONAL_PHOTO_CREATED'),
                Student::class, $student->id, $before,
                ['historical_photo' => null, 'historical_image_id' => $id, 'revision' => $current + 1, 'source' => $source, 'reason' => $correction ? $request->validated('reason') : null]);

            // Retain previous owned bytes and audit reference for institutional corrections.
            return $student->refresh()->load(['user', 'career']);
        });
    }

    private function conflict(string $code, string $message, int $revision): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message, 'current_revision' => $revision], 409));
    }
}
