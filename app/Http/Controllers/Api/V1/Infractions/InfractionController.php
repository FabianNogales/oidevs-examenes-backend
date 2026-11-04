<?php

namespace App\Http\Controllers\Api\V1\Infractions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Infractions\ListInfractionsRequest;
use App\Http\Requests\Infractions\SaveInfractionRequest;
use App\Http\Resources\Infractions\InfractionChangeResource;
use App\Http\Resources\Infractions\InfractionResource;
use App\Http\Resources\Infractions\InfractionTimelineResource;
use App\Models\Infraction;
use App\Services\Infractions\InfractionService;
use Illuminate\Http\Request;

class InfractionController extends Controller
{
    public function __construct(private readonly InfractionService $service) {}

    private function id(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($value === false, 404);

        return $value;
    }

    public function options()
    {
        return response()->json(['data' => [
            'types' => [['value' => 'COPYING', 'label' => 'Copia'], ['value' => 'CHEATING', 'label' => 'Trampa'],
                ['value' => 'IMPERSONATION', 'label' => 'Suplantación de identidad']],
            'statuses' => [['value' => 'REPORTED', 'label' => 'Reportada'], ['value' => 'CONFIRMED', 'label' => 'Confirmada'],
                ['value' => 'RESOLVED', 'label' => 'Resuelta']],
        ]]);
    }

    public function index(ListInfractionsRequest $request, int $examId)
    {
        return InfractionResource::collection($this->service->paginate($examId, $request->user(), $request->validated()))
            ->additional($this->service->context($examId, $request->user()));
    }

    public function students(ListInfractionsRequest $request, int $examId)
    {
        return response()->json($this->service->students($examId, $request->user(), $request->validated()));
    }

    public function studentHistory(ListInfractionsRequest $request, int $examId, string $studentId)
    {
        return InfractionResource::collection($this->service->studentHistory($examId, $this->id($studentId), $request->user(), $request->validated()));
    }

    public function show(ListInfractionsRequest $request, int $examId, string $infractionId)
    {
        return response()->json($this->detailPayload($this->service->detail($examId, $this->id($infractionId), $request->user()), $request));
    }

    private function detailPayload(Infraction $infraction, Request $request): array
    {
        return ['exam' => ['id' => (int) $infraction->exam_id, 'name' => $infraction->exam->name,
            'subject_name' => $infraction->exam->courseOffering->subject->name],
            'data' => (new InfractionResource($infraction))->resolve($request),
            'history' => InfractionTimelineResource::collection($infraction->getRelation('contractTimeline'))->resolve($request)];
    }

    public function contractStudentHistory(ListInfractionsRequest $request, int $examId, string $studentId)
    {
        $studentId = $this->id($studentId);
        $page = $this->service->studentHistory($examId, $studentId, $request->user(), $request->validated());
        $rows = $page->getCollection()->map(function ($infraction) use ($request) {
            $resource = new InfractionResource($infraction);
            $resource->readOnly = true;

            return ['exam' => ['id' => (int) $infraction->exam_id, 'name' => $infraction->exam->name,
                'subject_name' => $infraction->exam->courseOffering->subject->name], 'infraction' => $resource->resolve($request)];
        })->values();

        return response()->json(['context' => ['exam_id' => $examId, 'student_id' => $studentId], 'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function history(ListInfractionsRequest $request, int $examId, string $infractionId)
    {
        return InfractionChangeResource::collection($this->service->history($examId, $this->id($infractionId), $request->user(), $request->validated()));
    }

    public function store(SaveInfractionRequest $request, int $examId)
    {
        return (new InfractionResource($this->service->create($examId, $request->validated(), $request)))
            ->response()->setStatusCode(201);
    }

    public function update(SaveInfractionRequest $request, int $examId, string $infractionId)
    {
        return new InfractionResource($this->service->update($examId, $this->id($infractionId), $request->validated(), $request));
    }

    public function status(SaveInfractionRequest $request, int $examId, string $infractionId)
    {
        return response()->json($this->detailPayload(
            $this->service->update($examId, $this->id($infractionId), $request->validated(), $request, changeStatus: true), $request));
    }
}
