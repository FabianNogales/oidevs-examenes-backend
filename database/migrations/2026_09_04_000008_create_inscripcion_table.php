<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscripcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_materia_id');
            $table->foreignId('estudiante_id');
            $table->string('estado');
            $table->foreignId('registrado_por');
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['oferta_materia_id', 'estudiante_id'], 'uq_inscripcion_oferta_estudiante');
            $table->index('estudiante_id', 'idx_inscripcion_estudiante');
            $table->index('registrado_por', 'idx_inscripcion_registrado_por');
            $table->index('estado', 'idx_inscripcion_estado');

            $table->foreign('oferta_materia_id', 'fk_inscripcion_oferta')
                ->references('id')->on('oferta_materia')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_inscripcion_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('registrado_por', 'fk_inscripcion_registrado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscripcion');
    }
};
