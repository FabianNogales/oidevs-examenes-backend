<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\User;
use App\Services\Images\DatabaseImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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
            $student = Student::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = ['profile_photo' => $owner->profile_photo, 'profile_image_id' => $owner->profile_image_id];
            $images = app(DatabaseImageService::class);
            $id = $images->store($photoFile, 'profile', $student->id, $user->id);
            $owner->forceFill(['profile_image_id' => $id, 'profile_photo' => null])->save();
            DB::table('audit_logs')->insert([
                'user_id' => $user->id, 'action' => 'UPDATE_PROFILE_PHOTO',
                'entity_type' => User::class, 'entity_id' => $user->id,
                'old_values' => json_encode($before),
                'new_values' => json_encode(['profile_image_id' => $id]),
                'ip_address' => $ipAddress, 'user_agent' => $userAgent, 'created_at' => now(),
            ]);
            $images->discard($before['profile_image_id'], 'profile', $student->id);

            return $this->getProfileForUser($owner);
        });
    }
}
