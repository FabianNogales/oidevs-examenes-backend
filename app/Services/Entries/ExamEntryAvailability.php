<?php

namespace App\Services\Entries;

use App\Models\Exam;
use Carbon\CarbonImmutable;

class ExamEntryAvailability
{
    public const TIMEZONE = 'America/La_Paz';

    public const STATES = ['SCHEDULED', 'ACTIVE', 'IN_PROGRESS'];

    public static function startsAt(Exam $exam): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $exam->exam_date, 0, 10).' '.$exam->start_time, self::TIMEZONE);
    }

    public static function endsAt(Exam $exam): CarbonImmutable
    {
        return self::startsAt($exam)->addMinutes((int) $exam->duration_minutes);
    }

    public static function reason(Exam $exam): ?string
    {
        $reason = self::preparationReason($exam);
        if ($reason !== null) {
            return $reason;
        }

        return CarbonImmutable::now(self::TIMEZONE)->lessThan(self::startsAt($exam)) ? 'EXAM_NOT_STARTED' : null;
    }

    // Assignment, consultation and preloading remain possible before the start.
    public static function preparationReason(Exam $exam): ?string
    {
        return match (true) {
            $exam->status === 'CANCELLED' => 'EXAM_CANCELLED',
            in_array($exam->status, ['COMPLETED', 'FINISHED'], true) => 'EXAM_FINISHED',
            ! in_array($exam->status, self::STATES, true) => 'EXAM_STATE_NOT_ALLOWED',
            CarbonImmutable::now(self::TIMEZONE)->greaterThanOrEqualTo(self::endsAt($exam)) => 'EXAM_ENDED',
            default => null,
        };
    }
}
