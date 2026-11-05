<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamOfflinePackage extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'prepared_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
}
