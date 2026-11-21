<?php

namespace App\Services\Rooms;

use App\Models\Room;
use App\Services\Exams\ExamSchedulePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RoomAvailabilityService
{
    public const TIMEZONE = 'America/La_Paz';

    private const RESERVING_STATUSES = ['ACTIVE', 'SCHEDULED', 'IN_PROGRESS'];

    private readonly ExamSchedulePolicy $schedulePolicy;

    public function __construct(?ExamSchedulePolicy $schedulePolicy = null)
    {
        $this->schedulePolicy = $schedulePolicy ?? new ExamSchedulePolicy;
    }

    public function reservedMinutes(int $duration): int
    {
        return $this->schedulePolicy->reservedMinutes($duration);
    }

    public function interval(string $date, string $time, int $duration): array
    {
        $start = CarbonImmutable::parse($date.' '.$time, self::TIMEZONE);

        return [$start, $start->addMinutes($this->reservedMinutes($duration))];
    }

    public function overlaps(CarbonImmutable $start, ?CarbonImmutable $end = null): Builder
    {
        $period = ExamSchedulePolicy::PERIOD_MINUTES;
        $roundUp = $period - 1;

        [$startSql, $endSql] = match (DB::connection()->getDriverName()) {
            'pgsql' => [
                '(exam_date + start_time)',
                "(exam_date + start_time + (((CAST(duration_minutes AS BIGINT) + {$roundUp}) / {$period}) * {$period}) * INTERVAL '1 minute')",
            ],
            'sqlite' => [
                "datetime(exam_date || ' ' || start_time)",
                "datetime(exam_date || ' ' || start_time, '+' || (((duration_minutes + {$roundUp}) / {$period}) * {$period}) || ' minutes')",
            ],
            default => throw new \LogicException('El cálculo de disponibilidad requiere PostgreSQL o SQLite.'),
        };
        $query = DB::table('exams')->whereIn('status', self::RESERVING_STATUSES);
        // [start, end): a new exam can begin at the previous exam's reserved block end.
        $query->whereRaw($startSql.($end === null ? ' <= ?' : ' < ?'), [($end ?? $start)->format('Y-m-d H:i:s')])
            ->whereRaw($endSql.' > ?', [$start->format('Y-m-d H:i:s')]);

        return $query;
    }

    public function annotate(Collection $rooms, ?string $date = null): Collection
    {
        if ($rooms->isEmpty()) {
            return $rooms;
        }
        $start = $date === null ? CarbonImmutable::now(self::TIMEZONE) : CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        $end = $date === null ? null : $start->addDay();
        $query = $this->overlaps($start, $end);
        $ids = $rooms->pluck('id');
        $query->where(function ($query) use ($ids) {
            $query->whereIn('room_id', $ids);
            if (Schema::hasTable('exam_rooms')) {
                $query->orWhereIn('id', DB::table('exam_rooms')->whereIn('room_id', $ids)->select('exam_id'));
            }
        });
        $exams = $query->orderBy('exam_date')->orderBy('start_time')->orderBy('id')->get();
        $assignments = Schema::hasTable('exam_rooms') ? DB::table('exam_rooms')->whereIn('exam_id', $exams->pluck('id'))->get()->groupBy('exam_id') : collect();

        foreach ($rooms as $room) {
            $assigned = $exams->filter(fn ($exam) => (int) $exam->room_id === (int) $room->id
                || ($assignments->get($exam->id)?->contains('room_id', $room->id) ?? false));
            $exam = $assigned->first();
            $room->setAttribute('availability', $exam ? 'OCCUPIED' : 'AVAILABLE');
            $current = null;
            if ($exam) {
                [, $end] = $this->interval($exam->exam_date, $exam->start_time, (int) $exam->duration_minutes);
                $current = ['id' => (int) $exam->id, 'name' => $exam->name, 'exam_date' => $exam->exam_date,
                    'start_time' => $exam->start_time, 'end_time' => $end->format('H:i:s'),
                    'starts_at' => CarbonImmutable::parse($exam->exam_date.' '.$exam->start_time, self::TIMEZONE)->toIso8601String(),
                    'ends_at' => $end->toIso8601String()];
            }
            $room->setAttribute('current_exam', $current);
            $room->setAttribute('availability_date', $date ?? $start->toDateString());
            $room->setAttribute('reservation_count', $assigned->count());
        }

        return $rooms;
    }

    public function available(array $filters): Collection
    {
        app(\App\Services\CatalogLifecycle::class)->reactivateRooms(request());
        $query = Room::where('status', 'ACTIVE')->orderBy('id');
        if (isset($filters['exam_date'])) {
            [$start, $end] = $this->interval($filters['exam_date'], $filters['start_time'], (int) $filters['duration_minutes']);
            $occupied = $this->overlaps($start, $end);
            if (isset($filters['exclude_exam_id'])) {
                $occupied->where('id', '!=', $filters['exclude_exam_id']);
            }

            $occupiedExamIds = $occupied->pluck('id');
            if ($occupiedExamIds->isNotEmpty()) {
                $occupiedRoomIds = DB::table('exams')
                    ->whereIn('id', $occupiedExamIds)
                    ->pluck('room_id')
                    ->all();

                if (Schema::hasTable('exam_rooms')) {
                    $extraRoomIds = DB::table('exam_rooms')
                        ->whereIn('exam_id', $occupiedExamIds)
                        ->pluck('room_id')
                        ->all();
                    $occupiedRoomIds = array_values(array_unique(array_merge($occupiedRoomIds, $extraRoomIds)));
                }

                $query->whereNotIn('id', $occupiedRoomIds);
            }
        }

        return $query->get();
    }

    public function ensureReservable(
        int $roomId,
        string $date,
        string $time,
        int $duration,
        ?int $excludeExamId = null,
        bool $allowSharing = false
    ): void {
        // Shared rooms are disabled; $allowSharing is intentionally ignored.
        unset($allowSharing);

        // Called inside the exam transaction. The same room lock is used by state changes.
        $room = Room::query()->lockForUpdate()->find($roomId);
        if ($room !== null) {
            app(\App\Services\CatalogLifecycle::class)->reactivateLockedRoom($room, request());
        }
        if (! $room || $room->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['room_id' => 'El aula no está activa para nuevas reservas.']);
        }

        [$start, $end] = $this->interval($date, $time, $duration);
        $occupied = $this->overlaps($start, $end);
        if ($excludeExamId !== null) {
            $occupied->where('id', '!=', $excludeExamId);
        }

        $hasOverlapOnPrimary = (clone $occupied)->where('room_id', $roomId)->exists();
        $hasOverlapOnPivot = false;

        if (! $hasOverlapOnPrimary && Schema::hasTable('exam_rooms')) {
            $overlappingExamIds = (clone $occupied)->pluck('id');
            if ($overlappingExamIds->isNotEmpty()) {
                $hasOverlapOnPivot = DB::table('exam_rooms')
                    ->whereIn('exam_id', $overlappingExamIds)
                    ->where('room_id', $roomId)
                    ->exists();
            }
        }

        if ($hasOverlapOnPrimary || $hasOverlapOnPivot) {
            throw ValidationException::withMessages(['room_id' => 'El aula está ocupada durante el horario solicitado.']);
        }
    }
}
