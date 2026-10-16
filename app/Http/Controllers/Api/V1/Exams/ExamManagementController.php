<?php

namespace App\Http\Controllers\Api\V1\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\ListTeacherExamsRequest;
use App\Http\Resources\Exams\ExamManagementResource;
use App\Services\Exams\ExamManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExamManagementController extends Controller
{
    public function index(ListTeacherExamsRequest $request, ExamManagementService $exams): AnonymousResourceCollection
    {
        return ExamManagementResource::collection($exams->paginate($request->user(), $request->validated()));
    }

    public function show(Request $request, string $exam, ExamManagementService $exams): ExamManagementResource
    {
        return ExamManagementResource::make($exams->find($request->user(), $exam));
    }
}
