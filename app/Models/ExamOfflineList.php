<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamOfflineList extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['authorization_hash'];
}
