<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalServiceCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
