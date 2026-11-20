<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = ['rooms.view', 'rooms.create', 'rooms.update'];

    public function up(): void
    {
        // Reuse Block 1 catalog rows and assignments; never create duplicate permissions.
        if (DB::table('permissions')->whereIn('key', self::KEYS)->count() !== count(self::KEYS)) {
            throw new RuntimeException('Falta el catálogo de permisos de Aulas del Bloque 1.');
        }
        DB::table('permissions')->whereIn('key', self::KEYS)->update(['is_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', self::KEYS)->update(['is_enabled' => false]);
    }
};
