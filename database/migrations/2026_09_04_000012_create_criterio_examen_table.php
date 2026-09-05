<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criterio_examen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id');
            $table->foreignId('criterio_habilitacion_id');
            $table->boolean('es_obligatorio');
            $table->jsonb('parametros')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['examen_id', 'criterio_habilitacion_id'], 'uq_criterio_examen_examen_criterio');
            $table->index('criterio_habilitacion_id', 'idx_criterio_examen_criterio');

            $table->foreign('examen_id', 'fk_criterio_examen_examen')
                ->references('id')->on('examen')
                ->restrictOnDelete();
            $table->foreign('criterio_habilitacion_id', 'fk_criterio_examen_criterio')
                ->references('id')->on('criterio_habilitacion')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criterio_examen');
    }
};
