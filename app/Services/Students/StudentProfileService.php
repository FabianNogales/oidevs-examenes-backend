<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        return DB::transaction(function () use ($user, $photoFile, $ipAddress, $userAgent) {
            $oldPhotoPath = $user->profile_photo;

            // 1. Guardar nueva imagen en storage/app/public/avatars
            $newPhotoPath = $photoFile->store('avatars', 'public');

            // 2. Actualizar el usuario
            $user->update([
                'profile_photo' => $newPhotoPath,
            ]);

            // 3. Eliminar foto previa si existía
            if ($oldPhotoPath && Storage::disk('public')->exists($oldPhotoPath)) {
                Storage::disk('public')->delete($oldPhotoPath);
            }

            // 4. Registrar auditoría
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

            // Recargar datos actualizados del usuario
            $user->refresh();

            return $this->getProfileForUser($user);
        });
    }
}