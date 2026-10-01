<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.connections.pgsql.database') !== 'cardshop_testing'
            || (int) config('database.redis.cache.database') !== 11
            || (int) config('database.redis.default.database') !== 10) {
            throw new \RuntimeException('Tests require the isolated cardshop_testing database and Redis DBs 10/11.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        $this->mockConsoleOutput = false;
        parent::setUp();
        Cache::flush();
        settings_memo(clear: true);
    }
}
