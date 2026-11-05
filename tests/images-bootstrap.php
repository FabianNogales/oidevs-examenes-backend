<?php

// Abort before Laravel (and RefreshDatabase) can access any configured deploy DB.
$sqlite = getenv('DB_CONNECTION') === 'sqlite' && getenv('DB_DATABASE') === ':memory:';
$postgres = getenv('DB_CONNECTION') === 'pgsql' && getenv('DB_HOST') === '127.0.0.1'
    && getenv('DB_PORT') === '55439' && getenv('DB_DATABASE') === 'eida_images_test'
    && getenv('DB_USERNAME') === 'eida_images_test';
if (getenv('APP_ENV') !== 'testing' || getenv('DB_URL') !== '' || (! $sqlite && ! $postgres)
    || is_file(__DIR__.'/../bootstrap/cache/config.php')) {
    throw new RuntimeException('Image tests require the explicitly isolated SQLite or local PostgreSQL configuration; cached config is forbidden.');
}
require __DIR__.'/../vendor/autoload.php';
