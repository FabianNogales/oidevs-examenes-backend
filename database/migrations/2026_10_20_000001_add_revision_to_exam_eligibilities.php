<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL and SQLite: preserve existing rows and enforce positive revisions.
        $table = DB::connection()->getQueryGrammar()->wrapTable('exam_eligibilities');
        DB::statement("ALTER TABLE {$table} ADD COLUMN revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0)");
    }

    public function down(): void
    {
        Schema::table('exam_eligibilities', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
