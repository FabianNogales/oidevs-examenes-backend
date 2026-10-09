<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
        DB::statement(
            'ALTER TABLE academic_terms
             ADD CONSTRAINT academic_terms_date_range_check
             CHECK (end_date >= start_date)'
        );
    }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
        DB::statement(
            'ALTER TABLE academic_terms
             DROP CONSTRAINT academic_terms_date_range_check'
        );
    }
    }
};
