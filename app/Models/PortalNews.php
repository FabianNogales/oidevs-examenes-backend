<?php

namespace App\Models;

use App\Casts\PortalUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class PortalNews extends Model
{
    protected $table = 'portal_news';

    protected $fillable = ['title', 'summary', 'content'];

    protected $casts = [
        'revision' => 'integer',
        'published_at' => PortalUtcDateTime::class,
    ];
}
