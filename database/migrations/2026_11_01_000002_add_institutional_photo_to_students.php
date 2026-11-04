<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // No backfill: a current profile photo is not an institutional source.
            $table->string('historical_photo')->nullable();
            $table->unsignedInteger('historical_photo_revision')->default(0);
            $table->timestampTz('historical_photo_updated_at')->nullable();
            $table->foreignId('historical_photo_updated_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['historical_photo_updated_by']);
            $table->dropColumn(['historical_photo', 'historical_photo_revision', 'historical_photo_updated_at', 'historical_photo_updated_by']);
        });
    }
};
