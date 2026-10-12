<?php

namespace App\Http\Controllers\Api\V1\Entries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Entries\EntryControlRequest;
use App\Http\Requests\Entries\QrEntryRequest;
use App\Models\Exam;
use App\Services\Entries\QrEntryService;
use Illuminate\Http\JsonResponse;

class QrEntryController extends Controller
{
    public function __construct(private QrEntryService $service) {}

    public function store(QrEntryRequest $request, int $examId): JsonResponse
    {
        return $this->service->process($examId, $request->user(), $request->validated());
    }

    public function index(EntryControlRequest $request, int $examId): JsonResponse
    {
        return response()->json($this->service->history(Exam::findOrFail($examId), $request->validated()));
    }
}
