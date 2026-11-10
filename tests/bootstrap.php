<?php

require __DIR__.'/../vendor/autoload.php';

// Container env_file values can survive PHPUnit overrides when Laravel reboots.
// Refuse destructive database tests unless the process itself targets db-test.
foreach ([$_SERVER['DB_HOST'] ?? null, getenv('DB_HOST') ?: null] as $host) {
    if ($host !== null && $host !== 'db-test') {
        throw new RuntimeException('Database tests require the isolated compose test service (DB_HOST=db-test). Run: docker compose run --rm test');
    }
}
