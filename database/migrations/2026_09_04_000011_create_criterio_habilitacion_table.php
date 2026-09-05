<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criterio_habilitacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('origen');
            $table->string('tipo_criterio');
            $table->foreignId('creado_por');
            $table->string('estado');
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index('creado_por', 'idx_criterio_habilitacion_creado_por');
            $table->index('origen', 'idx_criterio_habilitacion_origen');
            $table->index('tipo_criterio', 'idx_criterio_habilitacion_tipo');
            $table->index('estado', 'idx_criterio_habilitacion_estado');

            $table->foreign('creado_por', 'fk_criterio_habilitacion_creado_por')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criterio_habilitacion');
    }
};
