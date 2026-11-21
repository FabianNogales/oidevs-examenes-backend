<?php

namespace App\Services;

use App\Rules\UniqueCatalogName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CatalogNameGuard
{
    public function check(string $table, string $name, ?int $except = null): void
    {
        // Serialize writers before checking, including CSV confirmations. Existing
        // duplicates are retained; no destructive unique-index migration is needed.
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(240025, ?)', [$table === 'rooms' ? 1 : 2]);
        }
        $unchanged = $except !== null && DB::table($table)->where('id', $except)->value('name') === $name;
        if (! $unchanged) {
            Validator::make(['name' => $name], ['name' => [new UniqueCatalogName($table, $except)]])->validate();
        }
    }
}
