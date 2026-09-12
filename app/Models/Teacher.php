<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'institutional_code',
        'identity_number',
        'first_names',
        'last_names',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
        ];
    }

    /**
     * The user account linked to the teacher.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query by teacher names, email or institutional code.
     *
     * @param  Builder<Teacher>  $query
     * @return Builder<Teacher>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $term = trim((string) preg_replace('/\s+/', ' ', (string) $search));

        if ($term === '') {
            return $query;
        }

        $likeTerm = '%'.strtolower($term).'%';

        return $query->where(function (Builder $query) use ($likeTerm): void {
            $query
                ->whereRaw('LOWER(first_names) LIKE ?', [$likeTerm])
                ->orWhereRaw('LOWER(last_names) LIKE ?', [$likeTerm])
                ->orWhereRaw('LOWER(institutional_code) LIKE ?', [$likeTerm])
                ->orWhereHas('user', function (Builder $query) use ($likeTerm): void {
                    $query->whereRaw('LOWER(email) LIKE ?', [$likeTerm]);
                });
        });
    }
}
