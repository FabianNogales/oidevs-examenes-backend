<?php

namespace App\Http\Controllers\Api\V1\Expulsions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expulsions\ListExpulsionsRequest;
use App\Http\Requests\Expulsions\StoreExpulsionRequest;
use App\Http\Resources\Expulsions\ExpulsionResource;
use App\Services\Expulsions\ExpulsionQueryService;
use App\Services\Expulsions\ExpulsionRegistrationService;

class ExpulsionController extends Controller
{
    public function index(ListExpulsionsRequest $request, int $examId, ExpulsionQueryService $service)
    {
        $page = $service->paginate($examId, $request->user(), $request->validated());

        return ExpulsionResource::collection($page)->additional(['message' => $page->total() === 0
            ? 'No se encontraron expulsiones con los criterios seleccionados.' : 'Expulsiones consultadas correctamente.']);
    }

    public function show(ListExpulsionsRequest $request, int $examId, string $expulsionId, ExpulsionQueryService $service)
    {
        return new ExpulsionResource($service->find($examId, $expulsionId, $request->user()));
    }

    public function students(ListExpulsionsRequest $request, int $examId, ExpulsionQueryService $service)
    {
        return response()->json($service->students($examId, $request->user(), $request->validated()));
    }

    public function studentHistory(ListExpulsionsRequest $request, int $examId, string $studentId, ExpulsionQueryService $service)
    {
        return ExpulsionResource::collection($service->studentHistory($examId, $studentId, $request->user(), $request->validated()));
    }

    public function store(StoreExpulsionRequest $request, int $examId, ExpulsionRegistrationService $service)
    {
        return (new ExpulsionResource($service->register($examId, $request->validated(), $request)))
            ->response()->setStatusCode(201);
    }
}
