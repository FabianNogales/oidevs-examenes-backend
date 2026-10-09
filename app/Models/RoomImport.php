<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomImport extends Model
{
    // Preserve the offset when writing timestamptz, regardless of PostgreSQL's session timezone.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['preview_id', 'user_id', 'file_hash', 'status', 'expires_at', 'preview_report', 'result_report', 'completed_at'];

    protected function casts(): array
    {
        return ['preview_report' => 'array', 'result_report' => 'array', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
