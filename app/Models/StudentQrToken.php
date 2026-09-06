<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentQrToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'token',
        'status',
        'generated_at',
        'revoked_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}