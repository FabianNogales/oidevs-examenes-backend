<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subjects\ListSubjectsRequest;
use App\Http\Requests\Api\V1\Subjects\SaveSubjectRequest;
use App\Http\Resources\Subjects\CareerResource;
use App\Http\Resources\Subjects\SubjectResource;
use App\Models\Career;
use App\Models\Subject;
use App\Services\Subjects\SubjectService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class SubjectController extends Controller
{
    public function store(SaveSubjectRequest $request, SubjectService $subjects): JsonResponse
    {
        return SubjectResource::make($subjects->save($request->validated(), $request))->response()->setStatusCode(201);
    }

    public function update(SaveSubjectRequest $request, Subject $subject, SubjectService $subjects): SubjectResource
    {
        return SubjectResource::make($subjects->save($request->validated(), $request, $subject));
    }

    public function index(ListSubjectsRequest $request, SubjectService $subjects): AnonymousResourceCollection
    {
        return SubjectResource::collection($subjects->paginate($request->validated()));
    }

    public function careers(): AnonymousResourceCollection
    {
        return CareerResource::collection(Career::query()->orderBy('name')->orderBy('id')->get());
    }

    public function show(Subject $subject): SubjectResource
    {
        return SubjectResource::make($subject->load(['careers' => fn ($query) => $query->orderBy('careers.name')->orderBy('careers.id')]));
    }
}
