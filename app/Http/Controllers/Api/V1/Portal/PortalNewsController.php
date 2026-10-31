<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Portal\ChangePortalNewsStatusRequest;
use App\Http\Requests\Api\V1\Portal\ListPortalNewsRequest;
use App\Http\Requests\Api\V1\Portal\PortalNewsImageRequest;
use App\Http\Requests\Api\V1\Portal\SavePortalNewsRequest;
use App\Http\Resources\Portal\PortalNewsResource;
use App\Services\Portal\PortalNewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalNewsController extends Controller
{
    public function index(ListPortalNewsRequest $request, PortalNewsService $service): AnonymousResourceCollection
    {
        return PortalNewsResource::collection($service->paginate($request->validated(), ! $request->routeIs('portal.admin.*')));
    }

    public function show(Request $request, int $news, PortalNewsService $service): PortalNewsResource
    {
        return PortalNewsResource::make($service->find($news, ! $request->routeIs('portal.admin.*')));
    }

    public function store(SavePortalNewsRequest $request, PortalNewsService $service): JsonResponse
    {
        return PortalNewsResource::make($service->save($request->validated(), $request))->response()->setStatusCode(201);
    }

    public function update(SavePortalNewsRequest $request, int $news, PortalNewsService $service): PortalNewsResource
    {
        return PortalNewsResource::make($service->save($request->validated(), $request, $news));
    }

    public function status(ChangePortalNewsStatusRequest $request, int $news, PortalNewsService $service): PortalNewsResource
    {
        return PortalNewsResource::make($service->changeStatus($news, $request->validated(), $request));
    }

    public function changeImage(PortalNewsImageRequest $request, int $news, PortalNewsService $service): PortalNewsResource
    {
        return PortalNewsResource::make($service->changeImage($news, $request->integer('revision'), $request->file('image'), $request));
    }

    public function image(Request $request, int $news, PortalNewsService $service): StreamedResponse
    {
        $record = $service->find($news, ! $request->routeIs('portal.admin.*'));
        abort_unless($record->image_path && Storage::disk('local')->exists($record->image_path), 404);

        return Storage::disk('local')->response($record->image_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
