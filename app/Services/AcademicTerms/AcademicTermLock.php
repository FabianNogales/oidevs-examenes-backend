<?php

namespace App\Services\AcademicTerms;

use Illuminate\Support\Facades\DB;

class AcademicTermLock
{
    public function acquire(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Las reglas de periodos requieren una transacción.');
        }
        if (DB::getDriverName() === 'pgsql') {
            // Shared by period mutations and future offering writers, including empty catalogues.
            DB::select('SELECT pg_advisory_xact_lock(240024, 1)');
        }
    }
}
