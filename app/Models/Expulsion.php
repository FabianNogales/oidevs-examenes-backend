<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expulsion extends Model
{
    protected static function booted(): void
    {
        static::created(function (self $record) {
            if ($record->discipline_case_id) return;
            $case = $record->infraction_id
                ? DisciplineCase::where('source_type', 'INFRACTION')->where('source_id', $record->infraction_id)->where('exam_id', $record->exam_id)->first()
                : null;
            $case ??= DisciplineCase::registerSource($record, 'LEGACY_EXPULSION');
            $record->discipline_case_id = $case->id;
            $record->saveQuietly();
        });
    }

    protected $fillable = ['exam_id', 'student_id', 'infraction_id', 'discipline_case_id', 'reason', 'expelled_at', 'expelled_by'];

    protected function casts(): array
    {
        return ['exam_id' => 'integer', 'student_id' => 'integer', 'infraction_id' => 'integer', 'discipline_case_id' => 'integer',
            'expelled_by' => 'integer', 'expelled_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function infraction(): BelongsTo
    {
        return $this->belongsTo(Infraction::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expelled_by');
    }

    public function scopeWithContext(Builder $query): Builder
    {
        return $query->with(['exam.courseOffering.subject', 'student', 'infraction', 'responsible.teacher', 'responsible.student']);
    }
}
