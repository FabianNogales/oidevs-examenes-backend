<?php

use App\Support\BrowserOrigins;

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the local first-party React origins that may send credentialed
    | requests to Laravel Sanctum and Fortify endpoints during development.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'user/password',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => BrowserOrigins::allowed(env('CORS_ALLOWED_ORIGINS'), env('FRONTEND_URL'), env('APP_ENV') === 'local'),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Report-Id', 'X-Report-Generated-At', 'Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
