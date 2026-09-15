<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;

class StudentProfileService
{
    public function getProfileForUser(User $user): Student
    {
        return Student::with(['user', 'career'])
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    public function updateProfilePhoto(User $user, UploadedFile $photoFile, ?string $ipAddress, ?string $userAgent): Student
    {
        $oldPhotoPath = $user->profile_photo;

        // 1. Guardar nueva imagen en storage
        $newPhotoPath = $photoFile->store('avatars', 'public');

        try {
            $student = DB::transaction(function () use ($user, $oldPhotoPath, $newPhotoPath, $ipAddress, $userAgent) {
                // 2. Actualizar la referencia en la base de datos
                $user->update([
                    'profile_photo' => $newPhotoPath,
                ]);

                // 3. Registrar auditoría
                DB::table('audit_logs')->insert([
                    'user_id'     => $user->id,
                    'action'      => 'UPDATE_PROFILE_PHOTO',
                    'entity_type' => User::class,
                    'entity_id'   => $user->id,
                    'old_values'  => json_encode(['profile_photo' => $oldPhotoPath]),
                    'new_values'  => json_encode(['profile_photo' => $newPhotoPath]),
                    'ip_address'  => $ipAddress,
                    'user_agent'  => $userAgent,
                    'created_at'  => now(),
                ]);

                $user->refresh();

                return $this->getProfileForUser($user);
            });

            // 4. Si la transacción fue exitosa, recién eliminar la foto anterior de Storage
            if ($oldPhotoPath && Storage::disk('public')->exists($oldPhotoPath)) {
                Storage::disk('public')->delete($oldPhotoPath);
            }

            return $student;

        } catch (Exception $e) {
            // Si la transacción o la auditoría fallan, revertir archivo nuevo subido
            if ($newPhotoPath && Storage::disk('public')->exists($newPhotoPath)) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $e;
        }
    }
}