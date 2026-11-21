<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Room;
use App\Services\AcademicTerms\AcademicTermLock;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogLifecycle
{
    public function refresh(Request $request): void
    {
        $today = CarbonImmutable::today('America/La_Paz')->toDateString();
        // Each transition and its audit commit together, once, even if scheduler
        // and HTTP requests run concurrently. End dates include the whole day.
        DB::transaction(function () use ($request, $today) {
            app(AcademicTermLock::class)->acquire();
            foreach (AcademicTerm::where('status', 'ACTIVE')->whereDate('end_date', '<', $today)->lockForUpdate()->get() as $term) {
                $term->update(['status' => 'INACTIVE']);
                app(AuditLogService::class)->log($request, null, 'ACADEMIC_TERM_EXPIRED', 'ACADEMIC_TERM', $term->id,
                    ['status' => 'ACTIVE'], ['status' => 'INACTIVE']);
            }
        });
        $this->reactivateRooms($request);
    }

    public function reactivateRooms(Request $request): void
    {
        DB::transaction(function () use ($request) {
            $instant = now()->utc()->format(DB::getDriverName() === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');
            foreach (Room::where('status', 'INACTIVE')->where('inactive_until', '<=', $instant)->orderBy('id')->lockForUpdate()->get() as $room) {
                $this->reactivateLockedRoom($room, $request);
            }
        });
    }

    /** The caller owns the room row lock and the surrounding transaction. */
    public function reactivateLockedRoom(Room $room, Request $request): void
    {
        if ($room->status === 'INACTIVE' && $room->inactive_until !== null && $room->inactive_until->lte(now()->utc())) {
            $before = $room->only(['status', 'inactive_until']);
            $room->update(['status' => 'ACTIVE', 'inactive_until' => null]);
            app(AuditLogService::class)->log($request, null, 'ROOM_AUTOMATICALLY_REACTIVATED', Room::class, $room->id,
                $before, ['status' => 'ACTIVE', 'inactive_until' => null]);
        }
    }
}
