<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expulsion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id');
            $table->foreignId('infraccion_id')->nullable();
            $table->text('motivo');
            $table->timestamp('expulsado_en')->useCurrent();
            $table->foreignId('expulsado_por');
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['examen_id', 'estudiante_id'], 'uq_expulsion_examen_estudiante');
            $table->index('estudiante_id', 'idx_expulsion_estudiante');
            $table->index('infraccion_id', 'idx_expulsion_infraccion');
            $table->index('expulsado_por', 'idx_expulsion_expulsado_por');

            $table->foreign('examen_id', 'fk_expulsion_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_expulsion_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('infraccion_id', 'fk_expulsion_infraccion')
                ->references('id')->on('infraccion')
                ->restrictOnDelete();
            $table->foreign('expulsado_por', 'fk_expulsion_expulsado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expulsion');
    }
};
