<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estudiante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id');
            $table->string('codigo_sis');
            $table->string('ci');
            $table->string('nombres');
            $table->string('apellidos');
            $table->foreignId('carrera_id');
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique('usuario_id', 'uq_estudiante_usuario');
            $table->unique('codigo_sis', 'uq_estudiante_codigo_sis');
            $table->unique('ci', 'uq_estudiante_ci');
            $table->index('carrera_id', 'idx_estudiante_carrera');
            $table->index('estado', 'idx_estudiante_estado');

            $table->foreign('usuario_id', 'fk_estudiante_usuario')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
            $table->foreign('carrera_id', 'fk_estudiante_carrera')
                ->references('id')->on('carrera')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estudiante');
    }
};
