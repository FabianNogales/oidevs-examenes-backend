<?php

namespace App\Models;

use App\Casts\AcademicUtcInstant;
use Illuminate\Database\Eloquent\Model;

class AcademicImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['preview' => 'array', 'result' => 'array', 'expires_at' => AcademicUtcInstant::class, 'confirmed_at' => AcademicUtcInstant::class,
            'created_at' => AcademicUtcInstant::class, 'updated_at' => AcademicUtcInstant::class];
    }
}
