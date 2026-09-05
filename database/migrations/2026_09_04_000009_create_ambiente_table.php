<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambiente', function (Blueprint $table) {
            $table->id();
            $table->string('codigo');
            $table->string('nombre')->nullable();
            $table->string('ubicacion')->nullable();
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique('codigo', 'uq_ambiente_codigo');
            $table->index('estado', 'idx_ambiente_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambiente');
    }
};
