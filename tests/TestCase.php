<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests run only against the in-memory SQLite database declared in phpunit.xml.
     * The check happens after the app boots but BEFORE `parent::setUp()`, because
     * that is where `RefreshDatabase` runs `migrate:fresh`: checking afterwards would
     * refuse the connection once the real database had already been wiped.
     */
    protected function setUp(): void
    {
        $this->refreshApplication();
        $this->assertInMemorySqlite();

        parent::setUp();
    }

    private function assertInMemorySqlite(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests must use SQLite in memory, got [{$connection}:{$database}]. See CLAUDE.md."
            );
        }
    }
}
