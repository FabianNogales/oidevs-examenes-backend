<?php

namespace App\Models;

use App\Casts\PortalUtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalNews extends Model
{
    protected $table = 'portal_news';

    protected $fillable = ['title', 'summary', 'content', 'category_id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PortalNewsCategory::class, 'category_id');
    }

    protected $casts = [
        'revision' => 'integer',
        'published_at' => PortalUtcDateTime::class,
    ];
}
