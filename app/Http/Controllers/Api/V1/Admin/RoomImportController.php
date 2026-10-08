<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rooms\ConfirmRoomImportRequest;
use App\Http\Requests\Api\V1\Rooms\PreviewRoomImportRequest;
use App\Services\Rooms\RoomImportService;
use Illuminate\Http\JsonResponse;

class RoomImportController extends Controller
{
    public function preview(PreviewRoomImportRequest $request, RoomImportService $imports): JsonResponse
    {
        return response()->json(['data' => $imports->preview($request->file('file'), $request->user()->id)]);
    }

    public function confirm(ConfirmRoomImportRequest $request, RoomImportService $imports): JsonResponse
    {
        return response()->json(['data' => $imports->confirm($request->file('file'), $request->validated('preview_id'), $request)]);
    }
}
