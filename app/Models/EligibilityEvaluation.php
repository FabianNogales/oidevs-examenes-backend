<?php

namespace App\Models;

use App\Casts\AcademicUtcInstant;
use Illuminate\Database\Eloquent\Model;

class EligibilityEvaluation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'results' => 'array', 'confirmation' => 'array', 'expires_at' => AcademicUtcInstant::class, 'confirmed_at' => AcademicUtcInstant::class,
            'created_at' => AcademicUtcInstant::class, 'updated_at' => AcademicUtcInstant::class];
    }
}
