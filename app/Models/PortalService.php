<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalService extends Model
{
    protected $fillable = [
        'category_id', 'name', 'description', 'icon_key', 'destination_type',
        'destination', 'requires_auth', 'sort_order',
    ];

    protected $casts = ['requires_auth' => 'boolean', 'sort_order' => 'integer', 'revision' => 'integer'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PortalServiceCategory::class, 'category_id');
    }
}
