<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->constrained('buildings')->restrictOnDelete();
            $table->timestampTz('inactive_until')->nullable()->index();
        });
        // Preserve legacy free-text locations. A building is assigned explicitly
        // rather than guessing that a location represents a registered building.
        DB::table('buildings')->insertOrIgnore([
            'name' => 'Edificio principal', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
            $table->dropIndex(['inactive_until']);
            $table->dropColumn('inactive_until');
        });
        Schema::dropIfExists('buildings');
    }
};
