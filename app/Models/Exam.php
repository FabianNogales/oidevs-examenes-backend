<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Room;

class Exam extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}