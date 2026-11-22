<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    public const ACTIVE = ['QUEUED', 'RUNNING', 'UPLOADING'];

    public const STORAGE_DATE_FORMAT = 'Y-m-d H:i:sP';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    // Never let a PostgreSQL session infer the timezone of an offset-free value.
    public function fromDateTime($value)
    {
        return empty($value) ? $value
            : $this->asDateTime($value)->utc()->format(self::STORAGE_DATE_FORMAT);
    }

    protected function casts(): array
    {
        return [
            'bytes' => 'integer', 'active_slot' => 'integer',
            'requested_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
        ];
    }
}
