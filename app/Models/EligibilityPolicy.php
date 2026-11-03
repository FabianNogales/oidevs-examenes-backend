<?php

namespace App\Models;

use App\Casts\AcademicUtcInstant;
use Illuminate\Database\Eloquent\Model;

class EligibilityPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'version' => 'integer', 'revision' => 'integer', 'activated_at' => AcademicUtcInstant::class,
            'created_at' => AcademicUtcInstant::class, 'updated_at' => AcademicUtcInstant::class];
    }
}
