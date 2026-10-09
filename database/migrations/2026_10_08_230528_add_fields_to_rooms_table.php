<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('description', 1000)->nullable();
            $table->integer('capacity')->nullable();
            $table->string('floor', 50)->nullable();

            // Conserva los valores actuales; cambia el default.
            $table->string('status')->default('ACTIVE')->change();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE rooms
                ADD CONSTRAINT rooms_capacity_positive_check
                CHECK (capacity IS NULL OR capacity > 0)
            ");

            DB::statement("
                ALTER TABLE rooms
                ADD CONSTRAINT rooms_status_check
                CHECK (status IN ('ACTIVE', 'INACTIVE'))
            ");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE rooms
                DROP CONSTRAINT rooms_capacity_positive_check
            ");

            DB::statement("
                ALTER TABLE rooms
                DROP CONSTRAINT rooms_status_check
            ");
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('status')->default(null)->change();
            $table->dropColumn(['description', 'capacity', 'floor']);
        });
    }
};