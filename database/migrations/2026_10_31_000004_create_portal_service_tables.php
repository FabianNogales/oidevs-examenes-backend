<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('portal_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('portal_service_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description');
            $table->string('icon_key', 50);
            $table->string('destination_type', 20);
            $table->string('destination', 2048);
            $table->boolean('requires_auth')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('INACTIVE');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'sort_order']);
            $table->index(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_services');
        Schema::dropIfExists('portal_service_categories');
    }
};
