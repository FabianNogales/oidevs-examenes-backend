<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultado_criterio_estudiante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id');
            $table->foreignId('criterio_habilitacion_id');
            $table->string('resultado');
            $table->text('observacion')->nullable();
            $table->foreignId('evaluado_por');
            $table->timestamp('evaluado_en')->useCurrent();

            $table->unique(
                ['examen_id', 'estudiante_id', 'criterio_habilitacion_id'],
                'uq_resultado_criterio_estudiante'
            );
            $table->index('estudiante_id', 'idx_resultado_criterio_estudiante');
            $table->index('criterio_habilitacion_id', 'idx_resultado_criterio_criterio');
            $table->index('evaluado_por', 'idx_resultado_criterio_evaluado_por');
            $table->index('resultado', 'idx_resultado_criterio_resultado');

            $table->foreign('examen_id', 'fk_resultado_criterio_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_resultado_criterio_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('criterio_habilitacion_id', 'fk_resultado_criterio_criterio')
                ->references('id')->on('criterio_habilitacion')
                ->restrictOnDelete();
            $table->foreign('evaluado_por', 'fk_resultado_criterio_evaluado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultado_criterio_estudiante');
    }
};
