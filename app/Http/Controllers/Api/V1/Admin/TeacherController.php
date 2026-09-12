<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Teachers\StoreTeacherRequest;
use App\Http\Requests\Api\V1\Teachers\UpdateTeacherRequest;
use App\Http\Requests\Api\V1\Teachers\UpdateTeacherStatusRequest;
use App\Http\Resources\Teachers\TeacherResource;
use App\Models\Teacher;
use App\Services\Teachers\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeacherController extends Controller
{
    public function __construct(private readonly TeacherService $teachers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return TeacherResource::collection($this->teachers->paginate($request->only('page', 'per_page', 'search')));
    }

    public function show(Teacher $teacher): TeacherResource
    {
        return TeacherResource::make($teacher->load('user'));
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $teacher = $this->teachers->create($request->validated(), $request->user(), $request);

        return TeacherResource::make($teacher)->response()->setStatusCode(201);
    }

    public function update(UpdateTeacherRequest $request, Teacher $teacher): TeacherResource
    {
        $teacher = $this->teachers->update($teacher, $request->validated(), $request->user(), $request);

        return TeacherResource::make($teacher);
    }

    public function updateStatus(UpdateTeacherStatusRequest $request, Teacher $teacher): TeacherResource
    {
        $teacher = $this->teachers->changeStatus($teacher, $request->validated('status'), $request->user(), $request);

        return TeacherResource::make($teacher);
    }
}
