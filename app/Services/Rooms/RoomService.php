<?php

namespace App\Services\Rooms;

use App\Models\Room;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function save(array $data, Request $request, ?Room $room = null): Room
    {
        $data = Arr::only($data, ['code', 'name', 'location', 'description', 'capacity', 'floor']);
        try {
            return DB::transaction(function () use ($data, $request, $room) {
                $creating = $room === null;
                $room = $creating ? new Room : Room::query()->lockForUpdate()->findOrFail($room->id);
                $old = $creating ? null : $room->only(['code', 'name', 'location', 'description', 'capacity', 'floor', 'status']);
                $room->fill($data);
                if ($creating) {
                    $room->status = 'ACTIVE';
                }
                $room->save();
                $this->audit->log($request, $request->user()->id,
                    $creating ? 'ROOM_CREATED' : 'ROOM_UPDATED', Room::class, $room->id,
                    $old, $room->only(['code', 'name', 'location', 'description', 'capacity', 'floor', 'status']));

                return $room;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Handle the DB constraint even if another request wins after validation.
            $message = $exception->getMessage();
            $field = str_contains($message, 'rooms_name_unique') || str_contains($message, 'rooms.name') ? 'name' : 'code';
            throw ValidationException::withMessages([
                $field => $field === 'name' ? 'El nombre ya está registrado.' : 'El código ya está registrado.',
            ]);
        }
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Room::query();
        $search = trim($filters['search'] ?? '');

        if ($search !== '') {
            // Treat SQL wildcard characters as literal search text.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('id')->paginate(15);
    }

    public function changeStatus(Room $room, string $status, Request $request): Room
    {
        return DB::transaction(function () use ($room, $status, $request) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            if ($room->status === $status) {
                return $room;
            }

            $oldStatus = $room->status;
            $room->status = $status;
            $room->save();
            $this->audit->log($request, $request->user()->id,
                $status === 'ACTIVE' ? 'ROOM_ACTIVATED' : 'ROOM_DEACTIVATED',
                Room::class, $room->id, ['status' => $oldStatus], ['status' => $status]);

            return $room;
        });
    }
}
