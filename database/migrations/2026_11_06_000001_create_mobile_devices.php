<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('installation_id');
            $table->string('name', 100);
            $table->string('platform', 10)->default('android');
            $table->string('app_version', 40)->nullable();
            $table->timestampTz('last_login_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'installation_id']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('mobile_device_id')->nullable()->constrained('mobile_devices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropConstrainedForeignId('mobile_device_id'));
        Schema::dropIfExists('mobile_devices');
    }
};
