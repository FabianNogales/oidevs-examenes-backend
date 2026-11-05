<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Services\Eligibility\Automation\AutomationSupport;
use App\Services\Eligibility\Automation\ConfigurationService;
use Illuminate\Http\Request;

class EligibilityAutomationController extends Controller
{
    public function __construct(private ConfigurationService $configuration, private AutomationSupport $support) {}

    public function options(Request $request, int $exam)
    {
        return $this->support->response(['data' => $this->configuration->options($this->support->exam($request, $exam))]);
    }

    public function show(Request $request, int $exam)
    {
        return $this->support->response(['data' => $this->configuration->data($this->support->exam($request, $exam))]);
    }

    public function update(Request $request, int $exam)
    {
        return $this->support->response(['data' => $this->configuration->save($request, $exam)]);
    }
}
