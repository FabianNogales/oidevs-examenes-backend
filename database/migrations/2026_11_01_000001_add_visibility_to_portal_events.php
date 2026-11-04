<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_events', function (Blueprint $table) {
            // Existing institutional events retain their previously public scope.
            $table->string('visibility', 20)->default('PUBLIC');
            $table->index(['status', 'visibility', 'starts_at'], 'portal_events_public_calendar_index');
        });
    }

    public function down(): void
    {
        Schema::table('portal_events', function (Blueprint $table) {
            $table->dropIndex('portal_events_public_calendar_index');
            $table->dropColumn('visibility');
        });
    }
};
