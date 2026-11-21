<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('catalogs:refresh-statuses', function () {
    app(\App\Services\CatalogLifecycle::class)->refresh(\Illuminate\Http\Request::create('/catalogs/refresh', 'POST'));
    $this->info('Estados de períodos y aulas actualizados.');
})->purpose('Expire academic terms and reactivate temporarily inactive rooms');

Schedule::command('catalogs:refresh-statuses')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();
