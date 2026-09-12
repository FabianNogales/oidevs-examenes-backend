<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Student extends Model
{
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
}
