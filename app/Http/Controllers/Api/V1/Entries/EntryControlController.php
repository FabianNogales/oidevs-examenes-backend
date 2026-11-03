<?php

namespace App\Http\Controllers\Api\V1\Entries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Entries\EntryControlRequest;
use App\Models\Exam;
use App\Services\Entries\EntryAnnulmentAccess;
use App\Services\Entries\EntryControlService;
use Illuminate\Http\JsonResponse;

class EntryControlController extends Controller
{
    public function __construct(protected EntryControlService $service) {}

    public function index(EntryControlRequest $request, int $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);
        $data = $this->service->index($exam, $request->validated());
        $data['permissions'] = ['can_annul_entries' => app(EntryAnnulmentAccess::class)->allows($exam, $request->user())];

        return response()->json($data);
    }

    public function show(EntryControlRequest $request, int $examId, int $studentId): JsonResponse
    {
        return response()->json($this->service->show(Exam::findOrFail($examId), $studentId, $request->validated()));
    }
}
