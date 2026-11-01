<?php

namespace App\Models;

use App\Casts\PortalUtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalEvent extends Model
{
    protected $fillable = [
        'category_id', 'title', 'description', 'starts_at', 'ends_at', 'all_day',
        'location', 'status', 'revision', 'published_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'starts_at' => PortalUtcDateTime::class,
        'ends_at' => PortalUtcDateTime::class,
        'published_at' => PortalUtcDateTime::class,
        'all_day' => 'boolean',
        'revision' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PortalEventCategory::class, 'category_id');
    }
}
