<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habilitacion_examen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id');
            $table->string('estado');
            $table->text('motivo')->nullable();
            $table->foreignId('evaluado_por')->nullable();
            $table->timestamp('evaluado_en')->nullable();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['examen_id', 'estudiante_id'], 'uq_habilitacion_examen_estudiante');
            $table->index('estudiante_id', 'idx_habilitacion_examen_estudiante');
            $table->index('evaluado_por', 'idx_habilitacion_examen_evaluado_por');
            $table->index('estado', 'idx_habilitacion_examen_estado');

            $table->foreign('examen_id', 'fk_habilitacion_examen_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_habilitacion_examen_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('evaluado_por', 'fk_habilitacion_examen_evaluado_por')
                ->references('id')->on('usuario')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habilitacion_examen');
    }
};
