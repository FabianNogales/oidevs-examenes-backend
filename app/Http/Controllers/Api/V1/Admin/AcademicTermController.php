<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicTerms\ListAcademicTermsRequest;
use App\Http\Requests\Api\V1\AcademicTerms\SaveAcademicTermRequest;
use App\Http\Requests\Api\V1\AcademicTerms\UpdateAcademicTermStatusRequest;
use App\Http\Resources\AcademicTerms\AcademicTermResource;
use App\Services\AcademicTerms\AcademicTermService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicTermController extends Controller
{
    public function updateStatus(UpdateAcademicTermStatusRequest $request, int $academicTerm, AcademicTermService $terms): AcademicTermResource
    {
        return AcademicTermResource::make($terms->changeStatus($academicTerm, $request->validated('status'), $request));
    }

    public function store(SaveAcademicTermRequest $request, AcademicTermService $terms): JsonResponse
    {
        return AcademicTermResource::make($terms->save($request->validated(), $request))->response()->setStatusCode(201);
    }

    public function update(SaveAcademicTermRequest $request, int $academicTerm, AcademicTermService $terms): AcademicTermResource
    {
        $terms->find($academicTerm);

        return AcademicTermResource::make($terms->save($request->validated(), $request, $academicTerm));
    }

    public function index(ListAcademicTermsRequest $request, AcademicTermService $terms): AnonymousResourceCollection
    {
        return AcademicTermResource::collection($terms->paginate($request->validated()));
    }

    public function show(int $academicTerm, AcademicTermService $terms): AcademicTermResource
    {
        return AcademicTermResource::make($terms->find($academicTerm));
    }

    public function active(AcademicTermService $terms): AcademicTermResource|JsonResponse
    {
        $term = $terms->active();

        return $term ? AcademicTermResource::make($term) : response()->json(['data' => null]);
    }
}
