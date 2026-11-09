<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalNewsCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'revision' => 'integer'];
}
