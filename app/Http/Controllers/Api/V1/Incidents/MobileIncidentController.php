<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\SyncMobileIncidentRequest;
use App\Services\Incidents\IncidentQueryService;
use App\Services\Incidents\MobileIncidentSyncService;
use App\Services\Infractions\InfractionAccess;
use Illuminate\Http\Request;

class MobileIncidentController extends Controller
{
    public function sync(SyncMobileIncidentRequest $request, int $examId, MobileIncidentSyncService $service)
    {
        return response()->json(['data' => $service->sync($examId, $request->event(),
            $request->validated('event_json'), $request->validated('event_sha256'), $request)]);
    }

    public function context(Request $request, int $examId, IncidentQueryService $incidents)
    {
        $context = $incidents->context($examId, $request->user());
        // Same exact owner rule, evaluated by its existing access service.
        try { app(InfractionAccess::class)->exam($examId, $request->user()); $manage = true; }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 403) throw $error;
            $manage = false;
        }
        return response()->json(['data' => ['exam_id' => $examId, 'can_read_incidents' => true,
            'can_report_incidents' => $context['permissions']['can_create'], 'can_manage_discipline' => $manage,
            'server_time' => now()->toISOString()]]);
    }
}
