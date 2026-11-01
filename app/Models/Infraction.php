<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Infraction extends Model
{
    public const AUDIT_ACTIONS = ['INFRACTION_REPORTED', 'INFRACTION_UPDATED', 'INFRACTION_STATUS_CHANGED'];

    protected $fillable = ['exam_id', 'student_id', 'type', 'description', 'observations', 'reported_by', 'occurred_at', 'status'];

    protected function casts(): array
    {
        return ['exam_id' => 'integer', 'student_id' => 'integer', 'reported_by' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'entity_id')->where('entity_type', 'Infraction')->whereIn('action', self::AUDIT_ACTIONS);
    }

    public function scopeWithContext(Builder $query): Builder
    {
        return $query->with(['student', 'exam', 'reporter.teacher', 'reporter.student'])->withMax('changes as revision', 'id');
    }
}
