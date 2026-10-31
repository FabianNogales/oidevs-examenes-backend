<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamOfflineDistribution extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected $hidden = ['input_hash', 'exam_hash'];

    protected $casts = ['version' => 'integer', 'prepared_at' => 'immutable_datetime'];
}
