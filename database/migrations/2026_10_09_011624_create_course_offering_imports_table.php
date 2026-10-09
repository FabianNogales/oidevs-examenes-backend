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
        Schema::create('course_offering_imports', function (Blueprint $table) {
        $table->id();
        $table->uuid('preview_id')->unique();

        $table->foreignId('user_id')
            ->constrained('users')
            ->restrictOnDelete();

        $table->char('file_hash', 64);
        $table->string('status', 20)->default('PENDING');
        $table->timestampTz('expires_at');
        $table->jsonb('preview_report');
        $table->jsonb('result_report')->nullable();
        $table->timestampTz('completed_at')->nullable();
        $table->timestamps();
    });

    if (DB::getDriverName() === 'pgsql') {
        DB::statement(
            "ALTER TABLE course_offering_imports
             ADD CONSTRAINT course_offering_imports_status_check
             CHECK (status IN ('PENDING', 'PROCESSING', 'COMPLETED'))"
        );
    }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_offering_imports');
    }
};
