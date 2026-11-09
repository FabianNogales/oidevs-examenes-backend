<?php

// Guard before Laravel boot or any test can open the configured database.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== ':memory:' || getenv('DB_URL') !== ''
    || is_file(__DIR__.'/../bootstrap/cache/config.php')) {
    throw new RuntimeException('Mobile tests require uncached testing configuration and SQLite :memory: only.');
}
require __DIR__.'/../vendor/autoload.php';
