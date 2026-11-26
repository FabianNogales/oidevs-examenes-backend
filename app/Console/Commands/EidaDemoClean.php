<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoBackupGuard;
use App\Services\Demo\LegacyDemoCleanup;
use Illuminate\Console\Command;

class EidaDemoClean extends Command
{
    protected $signature = 'eida:demo-clean {--execute : Ejecutar el plan, omitir para solo previsualizar}
        {--plan-hash= : SHA256 del plan previamente revisado}
        {--backup= : Ruta absoluta de un respaldo PostgreSQL recuperable}
        {--backup-sha256= : SHA256 del respaldo restaurado}
        {--restore-verified : Confirma una restauración de prueba exitosa de ese respaldo}';

    protected $description = 'Previsualiza y retira únicamente datos con manifiesto FULLQA v1';

    public function handle(LegacyDemoCleanup $cleanup, DemoBackupGuard $backup): int
    {
        try {
            $plan = $cleanup->preview();
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line('PLAN_SHA256='.$cleanup->hash($plan));
            if (! $this->option('execute')) {
                return $plan['blockers'] ? self::FAILURE : self::SUCCESS;
            }
            $hash = $backup->verify($this->options());
            $cleanup->execute((string) $this->option('plan-hash'), $hash);
            $this->info('Retiro completado. Auditoría y cuentas históricas conservadas.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
