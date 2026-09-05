<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rol_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id');
            $table->foreignId('rol_id');
            $table->timestamp('asignado_en')->useCurrent();
            $table->foreignId('asignado_por')->nullable();
            $table->string('estado');

            $table->unique(['usuario_id', 'rol_id'], 'uq_rol_usuario_usuario_rol');
            $table->index('rol_id', 'idx_rol_usuario_rol');
            $table->index('asignado_por', 'idx_rol_usuario_asignado_por');
            $table->index('estado', 'idx_rol_usuario_estado');

            $table->foreign('usuario_id', 'fk_rol_usuario_usuario')
                ->references('id')->on('usuario')
                ->restrictOnDelete();
            $table->foreign('rol_id', 'fk_rol_usuario_rol')
                ->references('id')->on('rol')
                ->restrictOnDelete();
            $table->foreign('asignado_por', 'fk_rol_usuario_asignado_por')
                ->references('id')->on('usuario')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_usuario');
    }
};
