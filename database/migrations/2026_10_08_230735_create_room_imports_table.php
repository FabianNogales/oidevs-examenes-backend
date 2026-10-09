<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_imports', function (Blueprint $table) {
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

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE room_imports
                ADD CONSTRAINT room_imports_status_check
                CHECK (status IN ('PENDING', 'PROCESSING', 'COMPLETED'))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_imports');
    }
};