<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcademicTerms\ListAcademicTermsRequest;
use App\Http\Resources\AcademicTerms\AcademicTermResource;
use App\Services\AcademicTerms\AcademicTermService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicTermController extends Controller
{
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
