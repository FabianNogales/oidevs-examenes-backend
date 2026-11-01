<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\ListIncidentsRequest;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Resources\Incidents\IncidentResource;
use App\Services\Incidents\IncidentQueryService;
use App\Services\Incidents\IncidentRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncidentController extends Controller
{
    public function index(ListIncidentsRequest $request, int $examId, IncidentQueryService $service): AnonymousResourceCollection
    {
        return IncidentResource::collection($service->paginate($examId, $request->user(), $request->validated()));
    }

    public function show(Request $request, int $examId, int $incidentId, IncidentQueryService $service): IncidentResource
    {
        return new IncidentResource($service->find($examId, $incidentId, $request->user()));
    }

    public function store(StoreIncidentRequest $request, int $examId, IncidentRegistrationService $service): JsonResponse
    {
        $incident = $service->register($examId, $request->validated(), $request);

        return response()->json([
            'message' => 'Incidencia registrada correctamente.',
            'data' => (new IncidentResource($incident))->resolve($request),
        ], 201);
    }
}
