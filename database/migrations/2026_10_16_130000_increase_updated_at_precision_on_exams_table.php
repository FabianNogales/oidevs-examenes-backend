<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.exams ALTER COLUMN updated_at TYPE timestamp(6) without time zone');
        }
        // SQLite stores timestamps as text and does not enforce fractional precision.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Returning to seconds discards fractional precision on rollback.
            DB::statement('ALTER TABLE public.exams ALTER COLUMN updated_at TYPE timestamp(0) without time zone');
        }
    }
};
