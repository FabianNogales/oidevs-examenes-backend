<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caso_especial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id')->nullable();
            $table->string('tipo');
            $table->text('descripcion');
            $table->foreignId('reportado_por');
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('resuelto_en')->nullable();
            $table->foreignId('resuelto_por')->nullable();

            $table->index('examen_id', 'idx_caso_especial_examen');
            $table->index('estudiante_id', 'idx_caso_especial_estudiante');
            $table->index('reportado_por', 'idx_caso_especial_reportado_por');
            $table->index('resuelto_por', 'idx_caso_especial_resuelto_por');
            $table->index('tipo', 'idx_caso_especial_tipo');
            $table->index('estado', 'idx_caso_especial_estado');

            $table->foreign('examen_id', 'fk_caso_especial_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_caso_especial_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('reportado_por', 'fk_caso_especial_reportado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
            $table->foreign('resuelto_por', 'fk_caso_especial_resuelto_por')
                ->references('id')->on('usuario')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caso_especial');
    }
};
