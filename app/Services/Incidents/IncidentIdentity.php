<?php

namespace App\Services\Incidents;

final class IncidentIdentity
{
    // Separate the audit namespace while keeping existing manual IDs and JS-safe integers.
    public const AUTOMATIC_OFFSET = 4503599627370496;

    public static function automatic(int $eventId): int
    {
        if ($eventId < 1 || $eventId >= self::AUTOMATIC_OFFSET) {
            throw new \OverflowException('Incident event ID cannot be represented safely.');
        }

        return self::AUTOMATIC_OFFSET + $eventId;
    }
}
