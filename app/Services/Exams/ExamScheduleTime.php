<?php

namespace App\Services\Exams;

use Carbon\CarbonImmutable;

final class ExamScheduleTime
{
    public const TIMEZONE = 'America/La_Paz';

    public const FUTURE_START_ERROR = 'La fecha y hora del examen deben estar en el futuro.';

    public static function isFutureStart(string $date, string $time): bool
    {
        return CarbonImmutable::parse($date.' '.$time, self::TIMEZONE)
            ->greaterThan(CarbonImmutable::now(self::TIMEZONE));
    }
}
