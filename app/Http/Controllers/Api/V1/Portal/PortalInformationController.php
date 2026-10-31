<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Portal\ListPortalInformationRequest;
use App\Http\Requests\Api\V1\Portal\PortalInformationRevisionRequest;
use App\Http\Requests\Api\V1\Portal\SavePortalInformationRequest;
use App\Http\Resources\Portal\PortalInformationResource;
use App\Services\Portal\PortalInformationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortalInformationController extends Controller
{
    public function publicContent(PortalInformationService $service): JsonResponse
    {
        return response()->json(['data' => $service->publicContent()]);
    }

    public function index(ListPortalInformationRequest $request, PortalInformationService $service): AnonymousResourceCollection
    {
        return PortalInformationResource::collection($service->paginate($this->kind($request), $request->validated()));
    }

    public function show(Request $request, int $content, PortalInformationService $service): PortalInformationResource
    {
        return PortalInformationResource::make($service->find($this->kind($request), $content));
    }

    public function store(SavePortalInformationRequest $request, PortalInformationService $service): JsonResponse
    {
        return PortalInformationResource::make($service->save('FAQ', $request->validated(), $request))->response()->setStatusCode(201);
    }

    public function update(SavePortalInformationRequest $request, int $content, PortalInformationService $service): PortalInformationResource
    {
        return PortalInformationResource::make($service->save($this->kind($request), $request->validated(), $request, $content));
    }

    public function publish(PortalInformationRevisionRequest $request, int $content, PortalInformationService $service): PortalInformationResource
    {
        return PortalInformationResource::make($service->publish($this->kind($request), $content, $request->integer('revision'), true, $request));
    }

    public function unpublish(PortalInformationRevisionRequest $request, int $content, PortalInformationService $service): PortalInformationResource
    {
        return PortalInformationResource::make($service->publish($this->kind($request), $content, $request->integer('revision'), false, $request));
    }

    private function kind(Request $request): string
    {
        return $request->routeIs('portal.admin.information.faqs.*') ? 'FAQ' : 'SECTION';
    }
}
