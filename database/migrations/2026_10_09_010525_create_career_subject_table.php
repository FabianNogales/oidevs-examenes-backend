<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('career_subject', function (Blueprint $table) {
    $table->id();

    $table->foreignId('career_id')
        ->constrained('careers')
        ->restrictOnDelete();

    $table->foreignId('subject_id')
        ->constrained('subjects')
        ->restrictOnDelete();

    $table->timestamps();

    $table->unique(['career_id', 'subject_id']);
    $table->index('subject_id');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_subject');
    }
};
