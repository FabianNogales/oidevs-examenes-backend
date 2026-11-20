<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SECTIONS = [
        'portal.news.update' => ['Portal · Noticias', 'Editar noticias'],
        'portal.events.update' => ['Portal · Calendario', 'Editar calendario'],
        'portal.information.sections.update' => ['Portal · Información institucional', 'Editar información institucional'],
        'portal.information.faqs.update' => ['Portal · Preguntas frecuentes', 'Editar preguntas frecuentes'],
        'portal.services.update' => ['Portal · Servicios', 'Editar servicios'],
    ];

    public function up(): void
    {
        foreach (self::SECTIONS as $key => [$module, $label]) {
            DB::table('permissions')->insert(['key' => $key, 'module' => $module, 'label' => $label,
                'description' => 'Encontrar y editar contenido existente de esta sección, incluidos borradores. Guardar contenido publicado actualiza el portal público. No autoriza creación, publicación, estado, visibilidad ni cambios de imágenes.',
                'is_configurable' => true, 'is_enabled' => true]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::SECTIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
