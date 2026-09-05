<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingreso_examen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('estudiante_id');
            $table->foreignId('ambiente_id');
            $table->foreignId('verificado_por');
            $table->string('metodo_verificacion');
            $table->timestamp('ingreso_en')->useCurrent();
            $table->string('estado');
            $table->timestamp('anulado_en')->nullable();
            $table->foreignId('anulado_por')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index('examen_id', 'idx_ingreso_examen_examen');
            $table->index('estudiante_id', 'idx_ingreso_examen_estudiante');
            $table->index('ambiente_id', 'idx_ingreso_examen_ambiente');
            $table->index('verificado_por', 'idx_ingreso_examen_verificado_por');
            $table->index('anulado_por', 'idx_ingreso_examen_anulado_por');
            $table->index('estado', 'idx_ingreso_examen_estado');
            $table->index('ingreso_en', 'idx_ingreso_examen_ingreso_en');

            $table->foreign('examen_id', 'fk_ingreso_examen_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('estudiante_id', 'fk_ingreso_examen_estudiante')
                ->references('id')->on('estudiante')
                ->restrictOnDelete();
            $table->foreign('ambiente_id', 'fk_ingreso_examen_ambiente')
                ->references('id')->on('ambiente')
                ->restrictOnDelete();
            $table->foreign('verificado_por', 'fk_ingreso_examen_verificado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
            $table->foreign('anulado_por', 'fk_ingreso_examen_anulado_por')
                ->references('id')->on('usuario')
                ->nullOnDelete();
        });

        DB::statement(
            "CREATE UNIQUE INDEX uq_ingreso_examen_valido_estudiante
            ON ingreso_examen (examen_id, estudiante_id)
            WHERE estado = 'VALIDO'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_ingreso_examen_valido_estudiante');

        Schema::dropIfExists('ingreso_examen');
    }
};
