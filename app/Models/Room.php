<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $table = 'rooms';

    protected $fillable = ['code', 'name', 'location', 'description', 'capacity', 'floor', 'status'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }
}
