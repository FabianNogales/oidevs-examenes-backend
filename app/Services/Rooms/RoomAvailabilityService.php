<?php

namespace App\Services\Rooms;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomAvailabilityService
{
    public const TIMEZONE = 'America/La_Paz';

    private const RESERVING_STATUSES = ['ACTIVE', 'SCHEDULED'];

    public function interval(string $date, string $time, int $duration): array
    {
        $start = CarbonImmutable::parse($date.' '.$time, self::TIMEZONE);

        return [$start, $start->addMinutes($duration)];
    }

    public function overlaps(CarbonImmutable $start, ?CarbonImmutable $end = null): Builder
    {
        [$startSql, $endSql] = match (DB::connection()->getDriverName()) {
            'pgsql' => ['(exam_date + start_time)', "(exam_date + start_time + duration_minutes * INTERVAL '1 minute')"],
            'sqlite' => ["datetime(exam_date || ' ' || start_time)", "datetime(exam_date || ' ' || start_time, '+' || duration_minutes || ' minutes')"],
            default => throw new \LogicException('El cálculo de disponibilidad requiere PostgreSQL o SQLite.'),
        };
        $query = DB::table('exams')->whereIn('status', self::RESERVING_STATUSES);
        // [start, end): a new exam can begin at the previous exam's end.
        $query->whereRaw($startSql.($end === null ? ' <= ?' : ' < ?'), [($end ?? $start)->format('Y-m-d H:i:s')])
            ->whereRaw($endSql.' > ?', [$start->format('Y-m-d H:i:s')]);

        return $query;
    }

    public function annotate(Collection $rooms): Collection
    {
        if ($rooms->isEmpty()) {
            return $rooms;
        }
        $now = CarbonImmutable::now(self::TIMEZONE);
        $exams = $this->overlaps($now)->whereIn('room_id', $rooms->pluck('id'))
            ->orderBy('exam_date')->orderBy('start_time')->orderBy('id')->get()->groupBy('room_id');

        foreach ($rooms as $room) {
            $exam = $exams->get($room->id)?->first();
            $room->setAttribute('availability', $exam ? 'OCCUPIED' : 'AVAILABLE');
            $current = null;
            if ($exam) {
                [, $end] = $this->interval($exam->exam_date, $exam->start_time, $exam->duration_minutes);
                $current = ['id' => (int) $exam->id, 'name' => $exam->name, 'exam_date' => $exam->exam_date,
                    'start_time' => $exam->start_time, 'end_time' => $end->format('H:i:s')];
            }
            $room->setAttribute('current_exam', $current);
        }

        return $rooms;
    }

    public function available(array $filters): Collection
    {
        $query = Room::where('status', 'ACTIVE')->orderBy('id');
        if (isset($filters['exam_date'])) {
            [$start, $end] = $this->interval($filters['exam_date'], $filters['start_time'], (int) $filters['duration_minutes']);
            $query->whereNotIn('id', $this->overlaps($start, $end)->select('room_id'));
        }

        return $query->get();
    }

    public function ensureReservable(int $roomId, string $date, string $time, int $duration): void
    {
        // Called inside the exam transaction. The same room lock is used by state changes.
        $room = Room::query()->lockForUpdate()->find($roomId);
        if (! $room || $room->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['room_id' => 'El aula no está activa para nuevas reservas.']);
        }
        [$start, $end] = $this->interval($date, $time, $duration);
        if ($this->overlaps($start, $end)->where('room_id', $roomId)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'El aula está ocupada durante el horario solicitado.']);
        }
    }
}
