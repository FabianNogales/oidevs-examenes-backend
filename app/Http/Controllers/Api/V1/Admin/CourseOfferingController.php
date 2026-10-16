<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CourseOfferings\ListCourseOfferingsRequest;
use App\Http\Resources\CourseOfferings\CourseOfferingResource;
use App\Services\CourseOfferings\CourseOfferingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CourseOfferingController extends Controller
{
    public function index(ListCourseOfferingsRequest $request, CourseOfferingService $offerings): AnonymousResourceCollection
    {
        return CourseOfferingResource::collection($offerings->paginate($request->validated()));
    }

    public function show(int $courseOffering, CourseOfferingService $offerings): CourseOfferingResource
    {
        return CourseOfferingResource::make($offerings->find($courseOffering));
    }
}
