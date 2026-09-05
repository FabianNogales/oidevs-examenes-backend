<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaborador_examen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id');
            $table->foreignId('asignado_por');
            $table->string('codigo_acceso_hash');
            $table->string('estado');
            $table->timestamp('asignado_en')->useCurrent();
            $table->timestamp('revocado_en')->nullable();

            $table->unique(['examen_id', 'estudiante_id'], 'uq_colaborador_examen_estudiante');
            $table->index('estudiante_id', 'idx_colaborador_examen_estudiante');
            $table->index('asignado_por', 'idx_colaborador_examen_asignado_por');
            $table->index('estado', 'idx_colaborador_examen_estado');

            $table->foreign('examen_id', 'fk_colaborador_examen_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_colaborador_examen_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('asignado_por', 'fk_colaborador_examen_asignado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_examen');
    }
};
