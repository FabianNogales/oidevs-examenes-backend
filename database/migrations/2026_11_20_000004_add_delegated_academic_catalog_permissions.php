<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATALOG = [
        'subjects.view' => ['Materias', 'Consultar materias', 'Catálogo global y detalle de materias y sus carreras.'],
        'subjects.create' => ['Materias', 'Crear materias', 'Registrar materias y asociarlas a carreras activas; sin importación ni estado.'],
        'subjects.update' => ['Materias', 'Editar materias', 'Código, nombre y asociaciones del catálogo global, conservando las reglas de carreras inactivas.'],
        'careers.view' => ['Carreras', 'Consultar carreras', 'Catálogo global y detalle; no existen escrituras delegadas.'],
        'academic_terms.view' => ['Periodos académicos', 'Consultar periodos', 'Catálogo global, detalle y consulta del periodo activo.'],
        'academic_terms.create' => ['Periodos académicos', 'Crear periodos inactivos', 'Registrar nombre y fechas; activación reservada al administrador.'],
        'academic_terms.update' => ['Periodos académicos', 'Editar periodos', 'Nombre y fechas, conservando estado y reglas de integridad.'],
        'course_offerings.view' => ['Ofertas', 'Consultar ofertas', 'Catálogo global: materia, periodo, identidad/contacto institucional docente y cantidades de matrículas/exámenes; sin acceso a esos registros.'],
    ];

    public function up(): void
    {
        foreach (self::CATALOG as $key => [$module, $label, $description]) {
            DB::table('permissions')->insert([
                'key' => $key, 'module' => $module, 'label' => $label, 'description' => $description,
                'is_configurable' => true, 'is_enabled' => true,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::CATALOG))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
