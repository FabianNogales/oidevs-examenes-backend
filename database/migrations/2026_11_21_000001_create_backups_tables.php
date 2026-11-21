<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name'); // Immutable snapshot, survives deactivation/deletion.
            $table->string('type', 20)->default('MANUAL_FULL');
            $table->string('status', 20)->index();
            $table->string('filename')->unique();
            $table->string('storage_provider', 30)->default('google_drive');
            $table->string('storage_file_id', 200)->nullable()->unique();
            $table->string('manifest_file_id', 200)->nullable()->unique();
            $table->string('storage_parent_id', 200)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('storage_version', 100)->nullable();
            $table->char('md5_checksum', 32)->nullable();
            $table->string('integrity_method', 30)->nullable();
            $table->string('postgres_version', 100)->nullable();
            $table->string('external_run_id', 100)->nullable()->unique();
            $table->timestampTz('requested_at')->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('deadline_at')->index();
            $table->string('error_code', 64)->nullable();
            // A database-enforced singleton, including across serverless instances.
            // SQL unique indexes allow multiple NULLs on PostgreSQL and SQLite.
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->index(['requested_by', 'requested_at']);
        });

        Schema::create('backup_callback_receipts', function (Blueprint $table) {
            $table->uuid('nonce')->primary();
            $table->foreignUuid('backup_id')->constrained('backups')->restrictOnDelete();
            $table->char('body_sha256', 64);
            $table->timestampTz('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_callback_receipts');
        Schema::dropIfExists('backups');
    }
};
