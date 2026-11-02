<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests wipe their database, so they only ever run on the in-memory one from phpunit.xml. A cached config
     * (php artisan config:cache / optimize) ignores phpunit.xml and would point them at the real database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];
        if ($app->configurationIsCached() || $config->get('database.default') !== 'sqlite' || $config->get('database.connections.sqlite.database') !== ':memory:') {
            fwrite(STDERR, "\nRefusing to run tests: they would wipe the '{$config->get('database.default')}' database. Run `php artisan config:clear` first.\n");
            exit(1);
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Feature tests exercise Inertia responses, not compiled assets. This keeps
        // a fresh checkout testable before npm install/build creates a manifest.
        $this->withoutVite();
    }
}
