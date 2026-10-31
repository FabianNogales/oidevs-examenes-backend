<?php

namespace App\Models;

use App\Casts\PortalUtcDateTime;
use Illuminate\Database\Eloquent\Model;

class PortalInformationContent extends Model
{
    protected $casts = [
        'draft' => 'array',
        'published' => 'array',
        'is_published' => 'boolean',
        'revision' => 'integer',
        'published_at' => PortalUtcDateTime::class,
    ];
}
