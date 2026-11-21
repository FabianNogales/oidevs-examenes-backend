<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    public const ACTIVE = ['QUEUED', 'RUNNING', 'UPLOADING'];

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

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
