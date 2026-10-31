<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_event_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('portal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('portal_event_categories')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'starts_at']);
            $table->index(['category_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_events');
        Schema::dropIfExists('portal_event_categories');
    }
};
