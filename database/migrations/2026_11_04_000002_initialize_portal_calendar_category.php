<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deploys already run migrate. Seed missing reference data without changing existing decisions.
        DB::table('portal_event_categories')->insertOrIgnore([
            'name' => 'Actividades académicas', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Reference data may now be used by events. Never delete it on rollback.
    }
};
