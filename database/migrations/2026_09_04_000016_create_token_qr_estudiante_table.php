<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_qr_estudiante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estudiante_id');
            $table->uuid('token');
            $table->string('estado');
            $table->timestamp('generado_en')->useCurrent();
            $table->timestamp('revocado_en')->nullable();

            $table->unique('token', 'uq_token_qr_estudiante_token');
            $table->index('estudiante_id', 'idx_token_qr_estudiante_estudiante');
            $table->index('estado', 'idx_token_qr_estudiante_estado');

            $table->foreign('estudiante_id', 'fk_token_qr_estudiante_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_qr_estudiante');
    }
};
