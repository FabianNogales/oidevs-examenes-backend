<?php

namespace App\Models;

use App\Models\Career;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'sis_code',
        'identity_number',
        'first_names',
        'last_names',
        'career_id',
        'status',
    ];

    /**
     * The user account linked to the student.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The career linked to the student.
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * The QR tokens generated for the student.
     */
    public function qrTokens(): HasMany
    {
        return $this->hasMany(StudentQrToken::class);
    }

    /** Buscar coincidencias parciales en SIS, nombres, apellidos y correo. */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', (string) $search));

        if ($term === '') {
            return $query;
        }

        $term = mb_strtolower($term, 'UTF-8');
        $likeTerm = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($likeTerm, $term): void {
            $query
                ->whereRaw('LOWER(sis_code) LIKE ?', [$likeTerm])
                ->orWhereRaw('LOWER(first_names) LIKE ?', [$likeTerm])
                ->orWhereRaw('LOWER(last_names) LIKE ?', [$likeTerm])
                ->orWhereHas('user', function (Builder $query) use ($likeTerm): void {
                    $query->whereRaw('LOWER(email) LIKE ?', [$likeTerm]);
                })
                ->orWhere(function (Builder $query) use ($term): void {
                    foreach (explode(' ', $term) as $word) {
                        $query->where(function (Builder $query) use ($word): void {
                            $query->whereRaw('LOWER(first_names) LIKE ?', ['%'.$word.'%'])
                                ->orWhereRaw('LOWER(last_names) LIKE ?', ['%'.$word.'%']);
                        });
                    }
                });
        });
    }
}
