<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Students\HistoricalPhotoRequest;
use App\Http\Resources\Students\StudentDetailResource;
use App\Models\Exam;
use App\Models\Student;
use App\Services\Entries\EntryControlService;
use App\Services\Images\DatabaseImageService;
use App\Services\Students\HistoricalPhotoService;
use Illuminate\Http\JsonResponse;
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
        return app(DatabaseImageService::class)->response($student->historical_image_id, 'historical', $student->id, $student->historical_photo);
    }

    public function entry(int $examId, int $studentId, EntryControlService $entry): StreamedResponse
    {
        $entry->show(Exam::findOrFail($examId), $studentId, []);

        return $this->show(Student::findOrFail($studentId));
    }
}
