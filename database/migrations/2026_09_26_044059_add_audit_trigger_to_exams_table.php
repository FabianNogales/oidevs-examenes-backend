<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION log_exam_creation()
                RETURNS TRIGGER AS $$
                BEGIN
                    INSERT INTO audit_logs (
                        action,
                        entity_type,
                        entity_id,
                        new_values,
                        created_at
                    ) VALUES (
                        'CREATE',
                        'Exam',
                        NEW.id,
                        row_to_json(NEW),
                        NOW()
                    );
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                
                CREATE TRIGGER exam_insert_audit_trigger
                AFTER INSERT ON exams
                FOR EACH ROW
                EXECUTE FUNCTION log_exam_creation();
            ");
        } elseif ($driver === 'sqlite') {
            // Sintaxis simplificada para que los tests en SQLite funcionen
            DB::unprepared("
                CREATE TRIGGER exam_insert_audit_trigger_sqlite
                AFTER INSERT ON exams
                FOR EACH ROW
                BEGIN
                    INSERT INTO audit_logs (action, entity_type, entity_id, new_values, created_at)
                    VALUES ('CREATE', 'Exam', NEW.id, '{}', CURRENT_TIMESTAMP);
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared("
                DROP TRIGGER IF EXISTS exam_insert_audit_trigger ON exams;
                DROP FUNCTION IF EXISTS log_exam_creation();
            ");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("
                DROP TRIGGER IF EXISTS exam_insert_audit_trigger_sqlite;
            ");
        }
    }
};