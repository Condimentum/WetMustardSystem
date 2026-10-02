<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard guard against ever running the test suite against a real database
     * (e.g. a stale `config:cache` masking phpunit.xml's sqlite override) -
     * this is exactly what deadlocked the live app for hours on 2026-10-02.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver !== 'sqlite') {
            self::fail(
                "Refusing to run tests: default DB connection '{$connection}' uses driver '{$driver}', not sqlite. ".
                'Config is likely cached with real credentials (run `php artisan config:clear`) - '.
                'tests must never run against the live database.'
            );
        }
    }
}
