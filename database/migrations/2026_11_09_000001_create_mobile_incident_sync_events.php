<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_incident_sync_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->foreignId('exam_id')->constrained('exams')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('package_id');
            $table->foreign('package_id')->references('id')->on('exam_offline_packages')->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->text('evidence_ciphertext');
            $table->jsonb('result')->nullable();
            $table->timestampTz('received_at');
            $table->index(['actor_id', 'exam_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('mobile_incident_sync_events')->exists()) {
            throw new RuntimeException('Existen reportes/evidencias. Se requiere una decisión institucional de conservación antes del rollback.');
        }
        Schema::dropIfExists('mobile_incident_sync_events');
    }
};
