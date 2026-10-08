<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamCollaborator extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'user_id', 'assigned_by', 'access_code_hash',
        'status', 'assigned_at', 'revoked_at',
    ];

    protected $hidden = ['access_code_hash'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public static function examIsAvailable(Exam $exam): bool
    {
        if (! in_array($exam->status, ['SCHEDULED', 'IN_PROGRESS'], true)) {
            return false;
        }

        $end = Carbon::parse($exam->exam_date.' '.$exam->start_time, config('app.timezone'))
            ->addMinutes((int) $exam->duration_minutes);

        return now()->lt($end);
    }
}
