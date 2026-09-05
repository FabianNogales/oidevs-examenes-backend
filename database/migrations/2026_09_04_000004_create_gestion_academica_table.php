<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestion_academica', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique('nombre', 'uq_gestion_academica_nombre');
            $table->index('estado', 'idx_gestion_academica_estado');
            $table->index(['fecha_inicio', 'fecha_fin'], 'idx_gestion_academica_fechas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestion_academica');
    }
};
