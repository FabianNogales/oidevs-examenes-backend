<?php

namespace App\Services\Infractions;

use App\Models\AuditLog;
use App\Models\Infraction;
use Carbon\CarbonImmutable;

class InfractionRevision
{
    public static function current(Infraction $infraction, ?AuditLog $latest = null): CarbonImmutable
    {
        $values = $latest?->new_values ?? $infraction->revision_snapshot ?? [];
        $stored = $values['version_updated_at'] ?? null;
        if ($stored !== null) {
            return CarbonImmutable::parse($stored)->utc();
        }

        return CarbonImmutable::parse($infraction->updated_at ?? $infraction->occurred_at)->utc();
    }

    public static function next(?CarbonImmutable $previous = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance(now())->utc();

        return $previous !== null && $now->lessThanOrEqualTo($previous) ? $previous->addMicrosecond() : $now;
    }
}
