<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Students\HistoricalPhotoRequest;
use App\Http\Resources\Students\StudentDetailResource;
use App\Models\Exam;
use App\Models\Student;
use App\Services\Entries\EntryControlService;
use App\Services\Students\HistoricalPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoricalPhotoController extends Controller
{
    public function store(HistoricalPhotoRequest $request, Student $student, HistoricalPhotoService $service): JsonResponse
    {
        $correction = $request->routeIs('students.historical-photo.correct');
        $record = $service->save($student->id, $request->file('photo'), $request->integer('revision'), $correction, $request);

        return StudentDetailResource::make($record)->response()->setStatusCode($correction ? 200 : 201);
    }

    public function show(Student $student): StreamedResponse
    {
        abort_unless($student->historical_photo && Storage::disk('local')->exists($student->historical_photo), 404);

        return Storage::disk('local')->response($student->historical_photo, null,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    public function entry(int $examId, int $studentId, EntryControlService $entry): StreamedResponse
    {
        $entry->show(Exam::findOrFail($examId), $studentId, []);

        return $this->show(Student::findOrFail($studentId));
    }
}
