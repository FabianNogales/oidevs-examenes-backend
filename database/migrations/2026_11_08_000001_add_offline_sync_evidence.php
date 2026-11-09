<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_offline_sync_events', function (Blueprint $table) {
            // Original wire evidence can include a QR credential. Encrypt using Laravel's existing key.
            $table->text('evidence_ciphertext')->nullable();
            $table->unsignedSmallInteger('evidence_schema_version')->nullable();
            $table->boolean('review_required')->default(false)->index();
        });
    }

    public function down(): void
    {
        if (DB::table('exam_offline_sync_events')->whereNotNull('evidence_ciphertext')->exists()) {
            throw new RuntimeException('Offline evidence exists. An institutional retention/archive decision is required before rollback.');
        }
        Schema::table('exam_offline_sync_events', function (Blueprint $table) {
            $table->dropIndex(['review_required']);
            $table->dropColumn(['evidence_ciphertext', 'evidence_schema_version', 'review_required']);
        });
    }
};
