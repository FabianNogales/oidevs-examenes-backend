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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function __construct(private readonly RoomService $rooms) {}

    public function index(ListRoomsRequest $request): AnonymousResourceCollection
    {
        return RoomResource::collection($this->rooms->paginate($request->validated()));
    }

    public function show(Room $room): RoomResource
    {
        // Route binding precedes the lifecycle middleware; read its updated state.
        return RoomResource::make($room->refresh());
    }

    /** Only the target's editable fields: editing does not grant catalog consultation. */
    public function edit(Room $room): JsonResponse
    {
        return response()->json(['data' => ['id' => (int) $room->id] + $room->only([
            'code', 'name', 'location', 'description', 'capacity', 'floor', 'building_id',
        ])]);
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
        return RoomResource::make($this->rooms->changeStatus($room, $request->validated('status'), $request, $request->validated('inactive_until')));
    }

    public function bulkActivate(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'room_ids' => ['required', 'array', 'min:1', 'max:500'],
            'room_ids.*' => ['required', 'integer', 'distinct', 'exists:rooms,id'],
        ]);
        $rooms = DB::transaction(function () use ($data, $request) {
            $rooms = Room::whereIn('id', $data['room_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($rooms->count() === count($data['room_ids']), 422, 'Un aula seleccionada ya no existe.');

            return $rooms->map(fn ($room) => $this->rooms->changeStatus($room, 'ACTIVE', $request));
        });

        return RoomResource::collection($rooms);
    }

    public function buildings(): JsonResponse
    {
        return response()->json(['data' => DB::table('buildings')->where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeBuilding(Request $request): JsonResponse
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'not_regex:/\p{Cc}/u']]);
        return DB::transaction(function () use ($data, $request) {
            // Reuse the room catalog lock to serialize building creation.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(240025, 1)');
            }
            \Illuminate\Support\Facades\Validator::make($data, ['name' => [new \App\Rules\UniqueCatalogName('buildings')]])->validate();
            $id = DB::table('buildings')->insertGetId($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            app(\App\Services\Audit\AuditLogService::class)->log($request, $request->user()->id, 'BUILDING_CREATED', 'Building', $id, null, $data);

            return response()->json(['data' => ['id' => $id] + $data], 201);
        });
    }
}
