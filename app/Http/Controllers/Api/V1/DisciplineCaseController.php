<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Discipline\{DisciplineService, DisciplinePresenter, DisciplineQuery};
use App\Enums\{IncidentType, InfractionType};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DisciplineCaseController extends Controller
{
    public function __construct(private DisciplineService $service, private DisciplinePresenter $presenter) {}

    public function index(Request $request, int $examId)
    {
        $filters = $this->filters($request);
        $context = $this->service->context($examId, $request);
        $page = app(DisciplineQuery::class)->listing($examId, $filters, $request);
        return response()->json($context + ['data' => $page->getCollection()->map(fn ($case) => $this->presenter->present($case, $request, $context['permissions'])),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['kind' => ['nullable', Rule::in(['INCIDENT', 'INFRACTION', 'LEGACY_EXPULSION'])],
            'request_key' => ['nullable', 'uuid'], 'search' => ['nullable', 'string'], 'measure' => ['nullable', Rule::in(['EXPELLED', 'NONE'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
    }

    public function export(Request $request, int $examId)
    {
        $filters = $this->filters($request);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'pdf'])]])['format'];
        $context = $this->service->context($examId, $request);
        abort_unless($context['permissions']['can_export'], 403);
        $records = app(DisciplineQuery::class)->listing($examId, $filters, $request, all: true)
            ->map(fn ($case) => $this->presenter->present($case, $request, $context['permissions']));
        $content = app(\App\Services\Discipline\DisciplineExport::class)->render($context['exam'], $records, $format);
        return response($content, 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="casos-examen-'.$examId.'.'.$format.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function resolve(Request $request, int $examId)
    {
        $data = $request->validate(['source_type' => ['required', Rule::in(['INCIDENT', 'INFRACTION', 'QR', 'LEGACY_EXPULSION', 'EXPULSION'])], 'source_id' => ['required', 'integer', 'min:1']]);
        $this->service->context($examId, $request);
        if ($data['source_type'] === 'EXPULSION') {
            abort_unless($this->service->context($examId, $request)['permissions']['can_manage'], 403);
            $measure = \App\Models\Expulsion::where('exam_id', $examId)->findOrFail($data['source_id']);
            return $this->show($request, $examId, $measure->discipline_case_id);
        }
        $case = \App\Models\DisciplineCase::where('exam_id', $examId)->where('source_type', $data['source_type'])->where('source_id', $data['source_id'])->firstOrFail();
        return $this->show($request, $examId, $case->id);
    }

    public function show(Request $request, int $examId, int $caseId)
    {
        $case = $this->service->find($examId, $caseId, $request);
        $context = $this->service->context($examId, $request);
        return response()->json($context + ['data' => $this->presenter->present($case, $request, $context['permissions'])]);
    }

    public function store(Request $request, int $examId)
    {
        $types = $request->input('kind') === 'INFRACTION' ? array_column(InfractionType::cases(), 'value') : [...array_column(IncidentType::cases(), 'value'), 'WRONG_EXAM'];
        $data = $request->validate(['kind' => ['required', Rule::in(['INCIDENT', 'INFRACTION'])], 'type' => ['required', Rule::in($types)],
            'description' => ['required', 'string'], 'observations' => ['nullable', 'string'],
            'student_id' => [Rule::requiredIf($request->input('kind') === 'INFRACTION' || $request->input('decision') === 'EXPEL'), 'nullable', 'integer', 'min:1', 'exists:students,id'],
            'decision' => ['required', Rule::in(['RECORD', 'EXPEL'])], 'request_key' => ['required', 'uuid'],
            'reason' => ['required_if:decision,EXPEL', 'nullable', 'string'], 'confirmed' => ['required_if:decision,EXPEL', 'accepted_if:decision,EXPEL']]);
        foreach (['description', 'reason', 'observations'] as $field) if (isset($data[$field])) $data[$field] = trim($data[$field]);
        \Illuminate\Support\Facades\Validator::make($data, ['description' => ['required'], 'reason' => ['required_if:decision,EXPEL']])->validate();
        $case = $this->service->create($examId, $data, $request);
        return $this->show($request, $examId, $case->id)->setStatusCode(201);
    }

    public function expel(Request $request, int $examId, int $caseId)
    {
        $data = $request->validate(['reason' => ['required', 'string'], 'confirmed' => ['required', 'accepted']]);
        $reason = trim($data['reason']);
        \Illuminate\Support\Facades\Validator::make(['reason' => $reason], ['reason' => ['required']])->validate();
        $this->service->expel($examId, $caseId, $reason, $request);
        return $this->show($request, $examId, $caseId);
    }
}
