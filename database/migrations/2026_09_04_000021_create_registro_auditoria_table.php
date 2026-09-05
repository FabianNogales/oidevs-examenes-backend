<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registro_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable();
            $table->string('accion');
            $table->string('tipo_entidad');
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->jsonb('valores_anteriores')->nullable();
            $table->jsonb('valores_nuevos')->nullable();
            $table->ipAddress('direccion_ip')->nullable();
            $table->text('agente_usuario')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index('usuario_id', 'idx_registro_auditoria_usuario');
            $table->index('accion', 'idx_registro_auditoria_accion');
            $table->index(['tipo_entidad', 'entidad_id'], 'idx_registro_auditoria_entidad');
            $table->index('creado_en', 'idx_registro_auditoria_creado_en');

            $table->foreign('usuario_id', 'fk_registro_auditoria_usuario')
                ->references('id')->on('usuario')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_auditoria');
    }
};
