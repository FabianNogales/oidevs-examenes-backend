<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Resources\Incidents\IncidentResource;
use App\Services\Incidents\IncidentRegistrationService;
use Illuminate\Http\JsonResponse;

class IncidentController extends Controller
{
    public function store(StoreIncidentRequest $request, int $examId, IncidentRegistrationService $service): JsonResponse
    {
        $incident = $service->register($examId, $request->validated(), $request);

        return response()->json([
            'message' => 'Incidencia registrada correctamente.',
            'data' => (new IncidentResource($incident))->resolve($request),
        ], 201);
    }
}
