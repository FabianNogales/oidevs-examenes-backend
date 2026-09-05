<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oferta_materia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('materia_id');
            $table->foreignId('gestion_academica_id');
            $table->foreignId('docente_usuario_id');
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index('materia_id', 'idx_oferta_materia_materia');
            $table->index('gestion_academica_id', 'idx_oferta_materia_gestion');
            $table->index('docente_usuario_id', 'idx_oferta_materia_docente');
            $table->index('estado', 'idx_oferta_materia_estado');

            $table->foreign('materia_id', 'fk_oferta_materia_materia')
                ->references('id')->on('materia')
                ->restrictOnDelete();
            $table->foreign('gestion_academica_id', 'fk_oferta_materia_gestion')
                ->references('id')->on('gestion_academica')
                ->restrictOnDelete();
            $table->foreign('docente_usuario_id', 'fk_oferta_materia_docente')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oferta_materia');
    }
};
