<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_news', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('summary');
            $table->text('content');
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_news');
    }
};
