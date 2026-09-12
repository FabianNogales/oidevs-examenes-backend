<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_offering_id',
        'room_id',
        'name',
        'exam_date',
        'start_time',
        'duration_minutes',
        'rules',
        'status',
        'created_by',
    ];

    protected $casts = [
        'exam_date' => 'date',
    ];

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }
}