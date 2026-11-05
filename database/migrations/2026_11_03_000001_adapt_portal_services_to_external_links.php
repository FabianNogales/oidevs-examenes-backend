<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Retain old category references, but the external-only API no longer requires one.
        Schema::table('portal_services', fn (Blueprint $t) => $t->unsignedBigInteger('category_id')->nullable()->change());
        DB::transaction(function () {
            foreach (DB::table('portal_services')->where('destination_type', 'INTERNAL')->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get() as $row) {
                DB::table('portal_services')->where('id', $row->id)->update(['status' => 'INACTIVE', 'revision' => $row->revision + 1, 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['user_id' => null, 'action' => 'PORTAL_SERVICE_INTERNAL_RETIRED', 'entity_type' => 'PortalService', 'entity_id' => $row->id,
                    'old_values' => json_encode(['status' => $row->status, 'revision' => $row->revision], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode(['status' => 'INACTIVE', 'revision' => $row->revision + 1, 'migration' => 'HU44_HU45_EXTERNAL_ONLY'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Never reactivate obsolete internal links or delete new category-less services on rollback.
        // Nullable legacy storage remains compatible; restoring the old functional scope is explicit.
    }
};
