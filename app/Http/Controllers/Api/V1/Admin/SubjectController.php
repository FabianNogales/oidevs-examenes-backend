<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subjects\ListSubjectsRequest;
use App\Http\Resources\Subjects\CareerResource;
use App\Http\Resources\Subjects\SubjectResource;
use App\Models\Career;
use App\Models\Subject;
use App\Services\Subjects\SubjectService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubjectController extends Controller
{
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
