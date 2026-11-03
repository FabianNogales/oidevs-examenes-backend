<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Preserve preview expiry instants even when PostgreSQL and PHP use different timezones. */
class AcademicUtcInstant implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $format = $model->getConnection()->getDriverName() === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s';

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($format);
    }
}
