<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Student;
use App\Services\Entries\EntryControlService;
use App\Services\Images\DatabaseImageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfilePhotoController extends Controller
{
    public function own(Request $request): StreamedResponse
    {
        return $this->show($request->user()->student()->firstOrFail());
    }

    // Admin role is enforced by the enclosing routes, matching historical photos.
    public function show(Student $student): StreamedResponse
    {
        $user = $student->user;

        return app(DatabaseImageService::class)->response($user?->profile_image_id, 'profile', $student->id, $user?->profile_photo);
    }

    public function entry(int $examId, int $studentId, EntryControlService $entry): StreamedResponse
    {
        $entry->show(Exam::findOrFail($examId), $studentId, []);

        return $this->show(Student::findOrFail($studentId));
    }
}
