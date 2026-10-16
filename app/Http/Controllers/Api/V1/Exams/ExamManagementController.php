<?php

namespace App\Http\Controllers\Api\V1\Exams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\CancelExamRequest;
use App\Http\Requests\Exams\ListTeacherExamsRequest;
use App\Http\Requests\Exams\UpdateExamRequest;
use App\Http\Resources\Exams\ExamManagementResource;
use App\Services\Exams\ExamCancellationService;
use App\Services\Exams\ExamManagementService;
use App\Services\Exams\ExamUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExamManagementController extends Controller
{
    public function update(UpdateExamRequest $request, string $exam, ExamUpdateService $updates): JsonResponse
    {
        $result = $updates->update($exam, $request->validated(), $request);

        return response()->json(['message' => 'El examen fue actualizado correctamente.', 'data' => ExamManagementResource::make($result)->resolve($request)]);
    }

    public function cancel(CancelExamRequest $request, string $exam, ExamCancellationService $cancellation): JsonResponse
    {
        $result = $cancellation->cancel($exam, $request->validated('expected_updated_at'), $request);

        return response()->json(['message' => 'El examen fue cancelado correctamente.', 'data' => ExamManagementResource::make($result)->resolve($request)]);
    }

    public function index(ListTeacherExamsRequest $request, ExamManagementService $exams): AnonymousResourceCollection
    {
        return ExamManagementResource::collection($exams->paginate($request->user(), $request->validated()));
    }

    public function show(Request $request, string $exam, ExamManagementService $exams): ExamManagementResource
    {
        return ExamManagementResource::make($exams->find($request->user(), $exam));
    }
}
