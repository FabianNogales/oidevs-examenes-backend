<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_offline_distributions', function (Blueprint $table) {
            $table->id();
            // V1 freezes one distribution per exam; regeneration needs an explicit future migration/workflow.
            $table->foreignId('exam_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('input_hash', 64);
            $table->string('exam_hash', 64);
            $table->timestampTz('prepared_at');
        });
        Schema::create('exam_offline_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('exam_offline_distributions')->restrictOnDelete();
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $table->string('authorization_hash', 64);
            $table->unsignedInteger('position');
            $table->unique(['distribution_id', 'responsible_id'], 'offline_list_responsible_unique');
        });
        Schema::create('exam_offline_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('list_id')->constrained('exam_offline_lists')->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            // Stronger than active-only uniqueness while V1 disallows regeneration.
            $table->unique(['exam_id', 'student_id'], 'offline_member_exam_student_unique');
            $table->unique(['list_id', 'position'], 'offline_member_position_unique');
        });
        Schema::create('exam_offline_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('list_id')->constrained('exam_offline_lists')->restrictOnDelete();
            $table->foreignId('downloaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('prepared_at');
            $table->timestampTz('expires_at');
            $table->jsonb('snapshot');
        });
        Schema::create('exam_offline_sync_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('list_id')->constrained('exam_offline_lists')->restrictOnDelete();
            $table->foreignUuid('package_id')->constrained('exam_offline_packages')->restrictOnDelete();
            $table->foreignId('synced_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('list_version');
            $table->string('payload_hash', 64);
            $table->timestampTz('captured_at');
            $table->timestampTz('received_at');
            $table->string('method', 6);
            $table->string('device_id', 100)->nullable();
            $table->jsonb('result')->nullable();
            $table->index(['exam_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_offline_sync_events');
        Schema::dropIfExists('exam_offline_packages');
        Schema::dropIfExists('exam_offline_members');
        Schema::dropIfExists('exam_offline_lists');
        Schema::dropIfExists('exam_offline_distributions');
    }
};
