<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rooms\ListRoomsRequest;
use App\Http\Requests\Api\V1\Rooms\SaveRoomRequest;
use App\Http\Requests\Api\V1\Rooms\UpdateRoomStatusRequest;
use App\Http\Resources\Rooms\RoomResource;
use App\Models\Room;
use App\Services\Rooms\RoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoomController extends Controller
{
    public function __construct(private readonly RoomService $rooms) {}

    public function index(ListRoomsRequest $request): AnonymousResourceCollection
    {
        return RoomResource::collection($this->rooms->paginate($request->validated()));
    }

    public function show(Room $room): RoomResource
    {
        return RoomResource::make($room);
    }

    public function store(SaveRoomRequest $request): JsonResponse
    {
        return RoomResource::make($this->rooms->save($request->validated(), $request))
            ->response()->setStatusCode(201);
    }

    public function update(SaveRoomRequest $request, Room $room): RoomResource
    {
        return RoomResource::make($this->rooms->save($request->validated(), $request, $room));
    }

    public function updateStatus(UpdateRoomStatusRequest $request, Room $room): RoomResource
    {
        return RoomResource::make($this->rooms->changeStatus($room, $request->validated('status'), $request));
    }
}
