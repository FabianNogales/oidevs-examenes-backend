<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario', function (Blueprint $table) {
            $table->id();
            $table->string('correo');
            $table->string('contrasena');
            $table->string('foto_perfil')->nullable();
            $table->string('estado');
            $table->timestamp('ultimo_acceso_en')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique('correo', 'uq_usuario_correo');
            $table->index('estado', 'idx_usuario_estado');
            $table->index('ultimo_acceso_en', 'idx_usuario_ultimo_acceso');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario');
    }
};
