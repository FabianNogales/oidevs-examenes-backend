<?php

namespace App\Models;

use App\Enums\IncidentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialCase extends Model
{
    protected $fillable = ['exam_id', 'student_id', 'type', 'description', 'reported_by', 'status'];

    protected function casts(): array
    {
        return [
            'exam_id' => 'integer', 'student_id' => 'integer', 'reported_by' => 'integer',
            'type' => IncidentType::class, 'resolved_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
