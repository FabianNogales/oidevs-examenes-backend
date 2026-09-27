<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Sintaxis correcta para PostgreSQL (Crear Función + Crear Trigger)
            DB::unprepared('
                CREATE OR REPLACE FUNCTION prevent_audit_log_modification()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION \'Violación de DB: Los registros de auditoría no pueden ser modificados o eliminados.\';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER prevent_audit_log_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_modification();
            ');
        } elseif ($driver === 'sqlite') {
            // Sintaxis para SQLite (Entorno de testing local)
            DB::unprepared('
                CREATE TRIGGER prevent_audit_log_update_sqlite
                BEFORE UPDATE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, \'Violación de seguridad: No se puede modificar\');
                END;

                CREATE TRIGGER prevent_audit_log_delete_sqlite
                BEFORE DELETE ON audit_logs
                BEGIN
                    SELECT RAISE(ABORT, \'Violación de seguridad: No se puede eliminar\');
                END;
            ');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS prevent_audit_log_update_delete ON audit_logs;
                DROP FUNCTION IF EXISTS prevent_audit_log_modification();
            ');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS prevent_audit_log_update_sqlite;
                DROP TRIGGER IF EXISTS prevent_audit_log_delete_sqlite;
            ');
        }
    }
};