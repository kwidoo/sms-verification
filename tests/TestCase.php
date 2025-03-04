<?php

namespace Kwidoo\SmsVerification\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Kwidoo\SmsVerification\SmsVerificationProvider;
use Mockery;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            SmsVerificationProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Load package configuration
        $app['config']->set('sms-verification', require __DIR__ . '/../config/sms-verification.php');

        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function defineDatabaseMigrations()
    {
        // Load package migrations if needed
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadLaravelMigrations();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Cache::flush();
        parent::tearDown();
    }
}
