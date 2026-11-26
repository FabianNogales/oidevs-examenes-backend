<?php

namespace App\Services\Demo;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Only the immutable FULLQA v1 manifests establish ownership, never a name/domain heuristic. */
class LegacyDemoCleanup
{
    private const DATASET = 'EIDA-FULLQA-v1';

    private const RETAIN = ['users', 'teachers', 'students', 'role_user', 'careers'];

    private const TABLES = ['users', 'teachers', 'students', 'role_user', 'careers', 'subjects', 'career_subject',
        'rooms', 'academic_terms', 'course_offerings', 'enrollments', 'exams', 'exam_eligibilities', 'exam_collaborators', 'exam_entries'];

    public function preview(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new DomainException('La limpieza requiere PostgreSQL.');
        }
        $retired = AuditLog::where('action', 'DEMO_DATASET_RETIRED')->where('entity_type', self::DATASET)->exists();
        $records = [];
        $stages = [];
        $blockers = [];
        foreach (AuditLog::where('action', 'DEMO_SEED_STAGE_CREATED')->where('entity_type', self::DATASET)->orderBy('id')->get() as $manifest) {
            $stage = $manifest->new_values['stage'] ?? '';
            if (isset($stages[$stage])) {
                throw new DomainException('Manifiestos FULLQA duplicados; se requiere revisión manual.');
            }
            $stages[$stage] = true;
            foreach ($manifest->new_values['records']['users'] ?? [] as $key => $id) {
                $local = match (true) {
                    $key === 'admin' => 'fullqa.admin',
                    preg_match('/\Ateacher([0-9]+)\z/', (string) $key, $parts) === 1 => 'fullqa.docente'.$parts[1],
                    preg_match('/\Astudent([0-9]+)\z/', (string) $key, $parts) === 1 => sprintf('fullqa.estudiante%02d', $parts[1]),
                    default => null,
                };
                $email = DB::table('users')->where('id', $id)->value('email');
                if (! $retired && ($local === null || strtolower((string) $email) !== $local.'@'.($manifest->new_values['domain'] ?? ''))) {
                    $blockers[] = 'users/'.$id.': identidad antigua ausente o modificada; revisar antes de desactivar';
                }
            }
            foreach ($manifest->new_values['records'] ?? [] as $table => $rows) {
                if (! in_array($table, self::TABLES, true)) {
                    throw new DomainException('Tabla no reconocida en el manifiesto antiguo: '.$table);
                }
                $records[$table] = array_values(array_unique(array_merge($records[$table] ?? [], array_values($rows))));
            }
        }
        $ambiguous = DB::table('users')->whereRaw('LOWER(email) LIKE ?', ['fullqa.%'])
            ->whereNotIn('id', $records['users'] ?? [])->orderBy('id')->pluck('id')->all();
        $knownUsers = $records['users'] ?? [];
        foreach (AuditLog::where('action', 'DEMO_DATASET_CREATED')->where('entity_type', 'EIDA-SPRINT123-v2')->get() as $manifest) {
            $knownUsers = array_merge($knownUsers, array_values($manifest->new_values['records']['users'] ?? []));
        }
        $suspected = DB::table('users')->whereNotIn('id', $knownUsers)->where(function ($query) {
            $query->whereRaw('LOWER(email) LIKE ?', ['%@eida.test'])->orWhereRaw('LOWER(email) LIKE ?', ['fullqa.%'])
                ->orWhereRaw('LOWER(profile_photo) LIKE ?', ['%dicebear%']);
        })->orderBy('id')->pluck('id')->all();
        $delete = [];
        $retain = [];
        foreach ($records as $table => $ids) {
            $present = DB::table($table)->whereIn('id', $ids)->orderBy('id')->pluck('id')->all();
            if (in_array($table, self::RETAIN, true)) {
                $retain[$table] = $present;
            } else {
                $delete[$table] = $present;
            }
        }
        if (! $retired) {
            // Include retained identities: external activity makes disabling those accounts ambiguous too.
            foreach ($this->foreignKeys() as $fk) {
                if (isset($records[$fk->child_table], $records[$fk->parent_table])
                    && DB::table($fk->child_table)->whereIn('id', $records[$fk->child_table])->whereNotNull($fk->child_column)
                        ->whereNotIn($fk->child_column, $records[$fk->parent_table])->exists()) {
                    $blockers[] = $fk->child_table.'.'.$fk->child_column.': registro demo reasociado fuera del manifiesto';
                }
                if (! isset($records[$fk->parent_table]) || $fk->child_table === 'audit_logs') {
                    continue;
                }
                $query = DB::table($fk->child_table)->whereIn($fk->child_column, $records[$fk->parent_table]);
                $query->whereNotIn('id', $records[$fk->child_table] ?? []);
                if ($count = $query->count()) {
                    $blockers[] = $fk->child_table.'.'.$fk->child_column.': '.$count.' referencias sin propiedad demostrada';
                }
            }
            // Sessions/tokens are polymorphic or lack a foreign key; do not silently revoke ambiguous activity.
            foreach (['personal_access_tokens' => 'tokenable_id', 'sessions' => 'user_id'] as $table => $column) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $query = DB::table($table)->whereIn($column, $records['users'] ?? []);
                if ($table === 'personal_access_tokens') {
                    $query->where('tokenable_type', (new User)->getMorphClass());
                }
                if ($query->exists()) {
                    $blockers[] = $table.': sesiones/credenciales posteriores; revisar y cerrar antes de retirar las cuentas';
                }
            }
        }

        return ['dataset' => self::DATASET, 'retired' => $retired, 'manifests' => count($stages), 'delete' => $delete,
            'retain_and_deactivate' => $retain, 'blockers' => array_values(array_unique($blockers)),
            'unowned_fullqa_user_ids' => $ambiguous,
            'unowned_demo_candidate_user_ids' => $suspected,
            'warning' => 'Se conservan auditorías, identidades antiguas y catálogos base. Nombres, correos o fotos DiceBear sin manifiesto no prueban propiedad. No se tocan respaldos.'];
    }

    public function execute(string $expectedPlanHash, string $backupHash): array
    {
        if (config('eida.allow_demo_seeding') !== true) {
            throw new DomainException('Requiere EIDA_ALLOW_DEMO_SEEDING=true.');
        }

        return app(AcademicTermIntegrity::class)->transaction(function () use ($expectedPlanHash, $backupHash) {
            DB::select('SELECT pg_advisory_xact_lock(240028, 1)');
            $tables = self::TABLES;
            foreach ($this->foreignKeys() as $fk) {
                if (in_array($fk->parent_table, self::TABLES, true)) {
                    $tables[] = $fk->child_table;
                }
            }
            $tables = array_unique(array_merge($tables, ['sessions', 'personal_access_tokens']));
            sort($tables);
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::statement('LOCK TABLE '.DB::getQueryGrammar()->wrapTable($table).' IN SHARE ROW EXCLUSIVE MODE');
            }
            $plan = $this->preview();
            if (! hash_equals($expectedPlanHash, $this->hash($plan))) {
                throw new DomainException('La previsualización cambió. Revisa el nuevo plan antes de ejecutar.');
            }
            if ($plan['retired'] || $plan['manifests'] === 0) {
                return $plan;
            }
            if ($plan['blockers']) {
                throw new DomainException('Limpieza bloqueada: '.implode('; ', $plan['blockers']));
            }
            // Reverse dependency order. Unknown descendants above block rather than being adopted/deleted.
            foreach (['exam_entries', 'exam_collaborators', 'exam_eligibilities', 'exams', 'enrollments', 'course_offerings',
                'career_subject', 'subjects', 'rooms', 'academic_terms'] as $table) {
                DB::table($table)->whereIn('id', $plan['delete'][$table] ?? [])->delete();
            }
            foreach (self::RETAIN as $table) {
                $ids = $plan['retain_and_deactivate'][$table] ?? [];
                if ($table === 'role_user') {
                    DB::table($table)->whereIn('id', $ids)->update(['status' => 'INACTIVE']);
                } else {
                    $values = ['status' => 'INACTIVE', 'updated_at' => now()];
                    if ($table === 'users') {
                        $values += ['active_session_id' => Str::random(40), 'remember_token' => null];
                    }
                    DB::table($table)->whereIn('id', $ids)->update($values);
                }
            }
            AuditLog::create(['action' => 'DEMO_DATASET_RETIRED', 'entity_type' => self::DATASET,
                'new_values' => ['plan_hash' => $expectedPlanHash, 'backup_sha256' => $backupHash, 'plan' => $plan], 'user_agent' => self::DATASET]);

            return $plan;
        });
    }

    public function hash(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    private function foreignKeys(): array
    {
        return DB::select("SELECT child.relname AS child_table, ca.attname AS child_column, parent.relname AS parent_table
            FROM pg_constraint fk
            JOIN pg_class child ON child.oid = fk.conrelid
            JOIN pg_namespace ns ON ns.oid = child.relnamespace
            JOIN pg_class parent ON parent.oid = fk.confrelid
            JOIN pg_attribute ca ON ca.attrelid = child.oid AND ca.attnum = fk.conkey[1]
            WHERE fk.contype = 'f' AND ns.nspname = current_schema()
            ORDER BY child.relname, ca.attname");
    }
}
