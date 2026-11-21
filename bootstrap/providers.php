<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    \App\Providers\BackupServiceProvider::class,
    FortifyServiceProvider::class,
];
