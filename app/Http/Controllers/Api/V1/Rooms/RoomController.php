<?php

namespace App\Http\Controllers\Api\V1\Rooms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rooms\AvailableRoomsRequest;
use App\Services\Rooms\RoomAvailabilityService;

class RoomController extends Controller
{
    public function index(AvailableRoomsRequest $request, RoomAvailabilityService $availability)
    {
        $rooms = $availability->available($request->validated());

        return response()->json(['data' => $rooms->map(fn ($room) => $room->only(['id', 'code', 'name', 'location', 'status']))]);
    }
}
