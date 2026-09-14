<?php

namespace App\Models;

use App\Models\Career;
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
}