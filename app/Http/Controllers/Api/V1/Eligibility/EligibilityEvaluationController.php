<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Models\EligibilityEvaluation;
use App\Services\Eligibility\Automation\AutomationSupport;
use App\Services\Eligibility\Automation\EvaluationService;
use Illuminate\Http\Request;

class EligibilityEvaluationController extends Controller
{
    public function __construct(private EvaluationService $service, private AutomationSupport $support) {}

    public function preview(Request $request, int $exam)
    {
        return $this->support->response(['data' => $this->service->view($request, $this->service->preview($request, $exam))]);
    }

    public function confirm(Request $request, int $exam, string $preview)
    {
        return $this->support->response(['data' => $this->service->confirm($request, $exam, $preview)]);
    }

    public function index(Request $request, int $exam)
    {
        $this->support->exam($request, $exam);

        return $this->support->response($this->support->page($request, EligibilityEvaluation::where('exam_id', $exam)
            ->select('id', 'preview_id', 'exam_id', 'eligibility_policy_id', 'created_by', 'created_at', 'confirmed_at', 'confirmation')->orderByDesc('id')));
    }

    public function show(Request $request, int $exam, int $evaluation)
    {
        $this->support->exam($request, $exam);
        $row = EligibilityEvaluation::where('exam_id', $exam)->findOrFail($evaluation);

        return $this->support->response(['data' => $this->service->view($request, $row)]);
    }
}
