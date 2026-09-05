<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_materia_id');
            $table->foreignId('ambiente_id');
            $table->string('nombre');
            $table->date('fecha_examen');
            $table->time('hora_inicio');
            $table->integer('duracion_minutos');
            $table->text('normas')->nullable();
            $table->string('estado');
            $table->foreignId('creado_por');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index('oferta_materia_id', 'idx_examen_oferta');
            $table->index('ambiente_id', 'idx_examen_ambiente');
            $table->index('creado_por', 'idx_examen_creado_por');
            $table->index('fecha_examen', 'idx_examen_fecha');
            $table->index('estado', 'idx_examen_estado');

            $table->foreign('oferta_materia_id', 'fk_examen_oferta')
                ->references('id')->on('oferta_materia')
                ->restrictOnDelete();
            $table->foreign('ambiente_id', 'fk_examen_ambiente')
                ->references('id')->on('ambiente')
                ->restrictOnDelete();
            $table->foreign('creado_por', 'fk_examen_creado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examen');
    }
};
