<?php

namespace App\Http\Controllers\Api\V1\Entries;

use App\Http\Controllers\Controller;
use App\Http\Requests\Entries\AnnulEntryRequest;
use App\Http\Resources\Entries\ExamEntryResource;
use App\Services\Entries\EntryAnnulmentService;
use Illuminate\Http\JsonResponse;

class EntryAnnulmentController extends Controller
{
    public function store(AnnulEntryRequest $request, int $examId, string $entryId, EntryAnnulmentService $service): JsonResponse
    {
        $entryId = filter_var($entryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($entryId === false, 404, 'Registro de ingreso no encontrado.');
        $data = $request->validated();
        $entry = $service->annul($examId, $entryId, $data['reason'], $request);

        return response()->json([
            'message' => 'Registro de ingreso anulado correctamente.',
            'data' => $request->routeIs('entries.annulment') ? $entry->history_record : (new ExamEntryResource($entry))->resolve($request),
        ]);
    }
}
