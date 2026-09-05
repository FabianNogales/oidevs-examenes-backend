<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infraccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id');
            $table->string('tipo');
            $table->text('descripcion');
            $table->foreignId('reportado_por');
            $table->timestamp('ocurrido_en')->useCurrent();
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();

            $table->index('examen_id', 'idx_infraccion_examen');
            $table->index('estudiante_id', 'idx_infraccion_estudiante');
            $table->index('reportado_por', 'idx_infraccion_reportado_por');
            $table->index('tipo', 'idx_infraccion_tipo');
            $table->index('estado', 'idx_infraccion_estado');

            $table->foreign('examen_id', 'fk_infraccion_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_infraccion_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('reportado_por', 'fk_infraccion_reportado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infraccion');
    }
};
