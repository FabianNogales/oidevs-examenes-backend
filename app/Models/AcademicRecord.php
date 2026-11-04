<?php

namespace App\Models;

use App\Casts\AcademicUtcInstant;
use Illuminate\Database\Eloquent\Model;

class AcademicRecord extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['assessments' => 'array', 'revision' => 'integer', 'student_id' => 'integer', 'attended_classes' => 'integer', 'total_classes' => 'integer',
            'created_at' => AcademicUtcInstant::class, 'updated_at' => AcademicUtcInstant::class];
    }
}
