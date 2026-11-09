<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

class MobileDevice extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $casts = ['last_login_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'mobile_device_id');
    }
}
