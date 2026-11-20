<?php

namespace App\Services\Exams;

use App\Enums\EvaluationType;
use App\Models\AcademicTerm;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ExamSchedulePolicy
{
    public const PERIOD_MINUTES = 90;

    public const MAX_PERIODS = 3;

    public const MIN_DURATION_MINUTES = 1;

    public const MAX_DURATION_MINUTES = self::PERIOD_MINUTES * self::MAX_PERIODS;

    public const DAY_END_TIME = '21:45:00';

    /**
     * @var list<string>
     */
    public const ALLOWED_START_TIMES = [
        '06:45:00',
        '08:15:00',
        '09:45:00',
        '11:15:00',
        '12:45:00',
        '14:15:00',
        '15:45:00',
        '17:15:00',
        '18:45:00',
        '20:15:00',
    ];

    public const INVALID_START_TIME_ERROR = 'La hora de inicio debe corresponder a uno de los periodos institucionales permitidos (06:45 a 20:15).';

    public const INVALID_DURATION_ERROR = 'La duración debe ser un entero entre 1 y 270 minutos (máximo 3 periodos).';

    public const DURATION_EXCEEDS_DAY_END_ERROR = 'La reserva por bloques de 90 minutos excede el horario límite institucional (21:45).';

    public function reservedMinutes(int $durationMinutes): int
    {
        if ($durationMinutes <= 0) {
            return 0;
        }

        return (int) ceil($durationMinutes / self::PERIOD_MINUTES) * self::PERIOD_MINUTES;
    }

    public function isAllowedStartTime(string $startTime): bool
    {
        return in_array($startTime, self::ALLOWED_START_TIMES, true);
    }

    public function fitsWithinInstitutionalDay(string $startTime, int $durationMinutes): bool
    {
        if (! $this->isAllowedStartTime($startTime) || $durationMinutes < self::MIN_DURATION_MINUTES) {
            return false;
        }

        $reservedEnd = CarbonImmutable::parse('2000-01-01 '.$startTime, 'UTC')
            ->addMinutes($this->reservedMinutes($durationMinutes));
        $dayEnd = CarbonImmutable::parse('2000-01-01 '.self::DAY_END_TIME, 'UTC');

        return $reservedEnd->lessThanOrEqualTo($dayEnd);
    }

    public function validateScheduleWindow(
        Validator $validator,
        mixed $examDate,
        mixed $startTime,
        mixed $durationMinutes
    ): void {
        $hasValidDateFormat = is_string($examDate)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate) === 1
            && checkdate((int) substr($examDate, 5, 2), (int) substr($examDate, 8, 2), (int) substr($examDate, 0, 4));
        $hasValidStart = ! $validator->errors()->has('start_time')
            && is_string($startTime);
        $hasValidDuration = ! $validator->errors()->has('duration_minutes')
            && is_numeric($durationMinutes);

        if ($hasValidStart && $hasValidDuration && ! $this->fitsWithinInstitutionalDay($startTime, (int) $durationMinutes)) {
            $validator->errors()->add('duration_minutes', self::DURATION_EXCEEDS_DAY_END_ERROR);
        }

        if ($hasValidDateFormat && $hasValidStart) {
            try {
                $isFuture = ExamScheduleTime::isFutureStart($examDate, $startTime);
            } catch (\Throwable) {
                return;
            }

            if (! $isFuture) {
                $validator->errors()->add('start_time', ExamScheduleTime::FUTURE_START_ERROR);
            }
        }
    }

    public function assertWithinAcademicTerm(?AcademicTerm $academicTerm, string $examDate): void
    {
        if (! $academicTerm) {
            return;
        }

        $normalizedExamDate = substr($examDate, 0, 10);
        $startDate = substr((string) $academicTerm->start_date, 0, 10);
        $endDate = substr((string) $academicTerm->end_date, 0, 10);

        if ($normalizedExamDate < $startDate || $normalizedExamDate > $endDate) {
            throw ValidationException::withMessages([
                'exam_date' => sprintf(
                    'La fecha del examen debe estar dentro del periodo académico %s (%s al %s).',
                    $academicTerm->name,
                    $startDate,
                    $endDate
                ),
            ]);
        }
    }

    public function resolvePartialNumber(string $evaluationType, mixed $rawPartialNumber, string $examName): ?int
    {
        $type = EvaluationType::tryFrom($evaluationType);
        if (! $type?->supportsPartialNumber()) {
            return null;
        }

        if (is_numeric($rawPartialNumber)) {
            return min(6, max(1, (int) $rawPartialNumber));
        }

        if (preg_match('/([1-6])/', $examName, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
