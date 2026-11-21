<?php

namespace App\Services\Rooms;

use App\Models\Room;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomService
{
    public function __construct(private readonly AuditLogService $audit, private readonly RoomAvailabilityService $availability,
        private readonly RoomAuthorization $access) {}

    public function save(array $data, Request $request, ?Room $room = null): Room
    {
        $data = Arr::only($data, ['code', 'name', 'location', 'description', 'capacity', 'floor', 'building_id']);
        try {
            return DB::transaction(function () use ($data, $request, $room) {
                $creating = $room === null;
                $actor = User::whereKey($request->user()->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                if ($request->hasSession() && $actor->active_session_id) {
                    abort_unless($actor->active_session_id === $request->session()->getId(), 401);
                }
                $this->access->authorize($actor, $creating ? 'rooms.create' : 'rooms.update');
                app(\App\Services\CatalogNameGuard::class)->check('rooms', trim($data['name']), $room?->id);
                $room = $creating ? new Room : Room::query()->lockForUpdate()->findOrFail($room->id);
                if ($creating && empty($data['code'])) {
                    $data['code'] = 'ROOM-'.Str::uuid();
                }
                if (isset($data['floor'])) {
                    if ($data['floor'] === 'PB') {
                        $data['floor'] = '0';
                    }
                    if (! preg_match('/\A-?[0-9]+\z/', (string) $data['floor']) || (int) $data['floor'] < -5 || (int) $data['floor'] > 200) {
                        throw ValidationException::withMessages(['floor' => 'El piso debe ser un entero entre -5 y 200; planta baja es 0.']);
                    }
                    $data['floor'] = (string) $data['floor'];
                }
                if (isset($data['capacity']) && (! filter_var($data['capacity'], FILTER_VALIDATE_INT) || $data['capacity'] < config('catalog_limits.room_min_capacity'))) {
                    throw ValidationException::withMessages(['capacity' => 'La capacidad mínima es de '.config('catalog_limits.room_min_capacity').' estudiantes.']);
                }
                if (isset($data['building_id'])) {
                    $building = DB::table('buildings')->where('id', $data['building_id'])->lockForUpdate()->first();
                    if (! $building || ! $building->is_active) {
                        throw ValidationException::withMessages(['building_id' => 'Selecciona un edificio registrado y activo.']);
                    }
                }
                $old = $creating ? null : $room->only(['code', 'name', 'location', 'description', 'capacity', 'floor', 'status', 'building_id']);
                $room->fill($data);
                if ($creating) {
                    $room->status = 'ACTIVE';
                }
                $room->save();
                $this->audit->log($request, $request->user()->id,
                    $creating ? 'ROOM_CREATED' : 'ROOM_UPDATED', Room::class, $room->id,
                    $old, $room->only(['code', 'name', 'location', 'description', 'capacity', 'floor', 'status', 'building_id']));

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

        $rooms = $query->orderBy('id')->paginate(15);
        $this->availability->annotate($rooms->getCollection(), $filters['date'] ?? null);

        return $rooms;
    }

    public function changeStatus(Room $room, string $status, Request $request, ?string $inactiveUntil = null): Room
    {
        return DB::transaction(function () use ($room, $status, $request, $inactiveUntil) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            $until = $status === 'INACTIVE' && $inactiveUntil !== null ? \Carbon\CarbonImmutable::parse($inactiveUntil)->utc() : null;
            if ($room->status === $status && ($room->inactive_until === null ? $until === null : $until !== null && $room->inactive_until->equalTo($until))) {
                return $room;
            }

            $hasDeadline = $room->inactive_until !== null || $until !== null;
            $fields = $hasDeadline ? ['status', 'inactive_until'] : ['status'];
            $before = $room->only($fields);
            $room->status = $status;
            $room->inactive_until = $until;
            $room->save();
            $this->audit->log($request, $request->user()->id,
                $status === 'ACTIVE' ? 'ROOM_ACTIVATED' : 'ROOM_DEACTIVATED',
                Room::class, $room->id, $before, $room->only($fields));

            return $room;
        });
    }
}
