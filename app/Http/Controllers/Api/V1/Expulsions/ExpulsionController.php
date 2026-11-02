<?php

namespace App\Http\Controllers\Api\V1\Expulsions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expulsions\StoreExpulsionRequest;
use App\Http\Resources\Expulsions\ExpulsionResource;
use App\Services\Expulsions\ExpulsionRegistrationService;

class ExpulsionController extends Controller
{
    public function store(StoreExpulsionRequest $request, int $examId, ExpulsionRegistrationService $service)
    {
        return (new ExpulsionResource($service->register($examId, $request->validated(), $request)))
            ->response()->setStatusCode(201);
    }
}
