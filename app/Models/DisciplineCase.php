<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DisciplineCase extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['exam_id' => 'integer', 'source_id' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function measure(): HasOne
    {
        return $this->hasOne(Expulsion::class);
    }

    public function source(): ?Model
    {
        return match ($this->source_type) {
            'INCIDENT' => SpecialCase::with('student', 'reporter')->find($this->source_id),
            'INFRACTION' => Infraction::withContext()->find($this->source_id),
            'QR' => AuditLog::find($this->source_id),
            'LEGACY_EXPULSION' => Expulsion::withContext()->find($this->source_id),
            default => null,
        };
    }

    public function studentId(): ?int
    {
        $source = $this->source();
        $id = $this->source_type === 'QR' ? ($source?->new_values['student_id'] ?? null) : $source?->student_id;

        return $id ? (int) $id : null;
    }

    public static function registerSource(Model $source, string $kind, ?int $examId = null): self
    {
        $identity = ['source_type' => $kind, 'source_id' => $source->id];
        // Atomic conflict handling without adding savepoints to the QR audit transaction.
        static::query()->insertOrIgnore($identity + [
            'exam_id' => $examId ?? $source->exam_id,
            'occurred_at' => $source->occurred_at ?? $source->expelled_at ?? $source->created_at ?? now(),
        ]);
        return static::query()->where($identity)->firstOrFail();
    }
}
