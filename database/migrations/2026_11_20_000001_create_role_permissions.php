<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false);
            $table->string('custom_name_key')->nullable()->unique();
            $table->unsignedInteger('revision')->default(1);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module');
            $table->string('label');
            $table->text('description');
            $table->boolean('is_configurable')->default(false);
            $table->boolean('is_enabled')->default(false);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // Frozen Block 1 catalog: configuring these actions does not enable any route.
        foreach ([
            'rooms.view' => ['Consultar aulas', 'Consultar el listado y el detalle de aulas.'],
            'rooms.create' => ['Crear aulas', 'Registrar un aula nueva.'],
            'rooms.update' => ['Editar aulas', 'Modificar los datos de un aula existente.'],
        ] as $key => [$label, $description]) {
            DB::table('permissions')->insert(['key' => $key, 'module' => 'Aulas', 'label' => $label,
                'description' => $description, 'is_configurable' => true, 'is_enabled' => false]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['custom_name_key']);
            $table->dropColumn(['is_custom', 'custom_name_key', 'revision']);
        });
    }
};
