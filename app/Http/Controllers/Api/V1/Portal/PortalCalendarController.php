<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Portal\ChangePortalEventStatusRequest;
use App\Http\Requests\Api\V1\Portal\ListPortalEventsRequest;
use App\Http\Requests\Api\V1\Portal\SavePortalEventRequest;
use App\Http\Resources\Portal\PortalEventResource;
use App\Models\PortalEventCategory;
use App\Services\Portal\PortalCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortalCalendarController extends Controller
{
    public function index(ListPortalEventsRequest $request, PortalCalendarService $calendar): AnonymousResourceCollection
    {
        return PortalEventResource::collection($calendar->paginate($request->validated(), ! $request->routeIs('portal.admin.*')));
    }

    public function show(Request $request, int $event, PortalCalendarService $calendar): PortalEventResource
    {
        return PortalEventResource::make($calendar->find($event, ! $request->routeIs('portal.admin.*')));
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => PortalEventCategory::where('is_active', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])]);
    }

    public function store(SavePortalEventRequest $request, PortalCalendarService $calendar): JsonResponse
    {
        return PortalEventResource::make($calendar->save($request->validated(), $request))->response()->setStatusCode(201);
    }

    public function update(SavePortalEventRequest $request, int $event, PortalCalendarService $calendar): PortalEventResource
    {
        return PortalEventResource::make($calendar->save($request->validated(), $request, $event));
    }

    public function status(ChangePortalEventStatusRequest $request, int $event, PortalCalendarService $calendar): PortalEventResource
    {
        return PortalEventResource::make($calendar->changeStatus($event, $request->validated(), $request));
    }
}
