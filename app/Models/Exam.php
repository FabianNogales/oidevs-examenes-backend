<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exam extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['course_offering_id' => 'integer', 'room_id' => 'integer', 'created_by' => 'integer', 'duration_minutes' => 'integer'];
    }

    public function scopeWithManagementRelations(Builder $query): Builder
    {
        return $query->with(['room', 'courseOffering.subject', 'courseOffering.academicTerm', 'courseOffering.teacher']);
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
