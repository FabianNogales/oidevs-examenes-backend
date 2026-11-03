<?php

namespace App\Http\Controllers\Api\V1\Expulsions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expulsions\EligibleExpulsionInfractionsRequest;
use App\Http\Requests\Expulsions\ListExpulsionsRequest;
use App\Http\Requests\Expulsions\StoreExpulsionRequest;
use App\Http\Resources\Expulsions\ExpulsionResource;
use App\Services\Expulsions\ExpulsionQueryService;
use App\Services\Expulsions\ExpulsionRegistrationService;

class ExpulsionController extends Controller
{
    public function index(ListExpulsionsRequest $request, int $examId, ExpulsionQueryService $service)
    {
        $page = $service->paginate($examId, $request->user(), $request->validated());

        return ExpulsionResource::collection($page)->additional($service->context($examId, $request->user()) + ['message' => $page->total() === 0
            ? 'No se encontraron expulsiones con los criterios seleccionados.' : 'Expulsiones consultadas correctamente.']);
    }

    public function show(ListExpulsionsRequest $request, int $examId, string $expulsionId, ExpulsionQueryService $service)
    {
        return (new ExpulsionResource($service->find($examId, $expulsionId, $request->user())))
            ->additional(['exam' => $service->context($examId, $request->user())['exam']]);
    }

    public function eligibleInfractions(EligibleExpulsionInfractionsRequest $request, int $examId, ExpulsionQueryService $service)
    {
        return response()->json($service->eligibleInfractions($examId, (int) $request->validated('student_id'), $request->user(), $request->validated()));
    }

    public function contractStudentHistory(ListExpulsionsRequest $request, int $examId, string $studentId, ExpulsionQueryService $service)
    {
        $page = $service->studentHistory($examId, $studentId, $request->user(), $request->validated());
        $rows = $page->getCollection()->map(fn ($expulsion) => [
            'exam' => ['id' => (int) $expulsion->exam_id, 'name' => $expulsion->exam->name,
                'subject_name' => $expulsion->exam->courseOffering->subject->name],
            'expulsion' => (new ExpulsionResource($expulsion))->resolve($request),
        ])->values();

        return response()->json(['context' => ['exam_id' => $examId, 'student_id' => (int) $studentId], 'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function students(ListExpulsionsRequest $request, int $examId, ExpulsionQueryService $service)
    {
        return response()->json($service->students($examId, $request->user(), $request->validated()));
    }

    public function studentHistory(ListExpulsionsRequest $request, int $examId, string $studentId, ExpulsionQueryService $service)
    {
        return ExpulsionResource::collection($service->studentHistory($examId, $studentId, $request->user(), $request->validated()));
    }

    public function store(StoreExpulsionRequest $request, int $examId, ExpulsionRegistrationService $service)
    {
        return (new ExpulsionResource($service->register($examId, $request->validated(), $request)))
            ->response()->setStatusCode(201);
    }
}
