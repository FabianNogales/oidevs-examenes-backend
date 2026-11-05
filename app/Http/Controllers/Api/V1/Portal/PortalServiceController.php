<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandlePortalServiceErrors;
use App\Http\Requests\Api\V1\Portal\ChangePortalServiceStatusRequest;
use App\Http\Requests\Api\V1\Portal\ListPortalServicesRequest;
use App\Http\Requests\Api\V1\Portal\SavePortalServiceRequest;
use App\Http\Resources\Portal\PortalServiceResource;
use App\Services\Portal\PortalServiceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;

class PortalServiceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [HandlePortalServiceErrors::class];
    }

    public function index(ListPortalServicesRequest $request, PortalServiceCatalog $catalog): AnonymousResourceCollection
    {
        return PortalServiceResource::collection($catalog->paginate($request->validated(), ! $request->routeIs('portal.admin.*')));
    }

    public function show(Request $request, int $service, PortalServiceCatalog $catalog): PortalServiceResource
    {
        return PortalServiceResource::make($catalog->find($service, ! $request->routeIs('portal.admin.*')));
    }

    public function store(SavePortalServiceRequest $request, PortalServiceCatalog $catalog): JsonResponse
    {
        return PortalServiceResource::make($catalog->save($request->validated(), $request))->response()->setStatusCode(201);
    }

    public function update(SavePortalServiceRequest $request, int $service, PortalServiceCatalog $catalog): PortalServiceResource
    {
        return PortalServiceResource::make($catalog->save($request->validated(), $request, $service));
    }

    public function status(ChangePortalServiceStatusRequest $request, int $service, PortalServiceCatalog $catalog): PortalServiceResource
    {
        return PortalServiceResource::make($catalog->changeStatus($service, $request->validated(), $request));
    }
}
