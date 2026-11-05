<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;

trait IsolatedOfflineDatabase
{
    public function refreshDatabase(): void
    {
        // Guard before any connection/migration. Each test application gets a new in-memory PDO database.
        if (! app()->environment('testing') || app()->configurationIsCached()
            || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:'
            || config('database.connections.sqlite.url')) {
            throw new \RuntimeException('Offline tests require uncached testing configuration and SQLite :memory: without DB_URL.');
        }
        Artisan::call('migrate', ['--force' => true]);
    }
}
