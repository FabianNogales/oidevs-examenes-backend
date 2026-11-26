<?php

// Fail before Laravel can read application database configuration or run migrations.
if (getenv('EIDA_HISTORICAL_PHOTO_PG') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_URL') !== ''
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55447'
    || getenv('DB_USERNAME') !== 'eida_hist_test'
    || ! preg_match('/\Aeida_hist_test_[a-f0-9]{12}\z/', (string) getenv('DB_DATABASE'))
    || is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Requires an explicitly isolated local historical-photo PostgreSQL test cluster.');
}

require __DIR__.'/../../vendor/autoload.php';
