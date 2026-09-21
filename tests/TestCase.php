<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use VendorName\LaravelPaymentReconciliation\PaymentReconciliationServiceProvider;
use VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PaymentReconciliationServiceProvider::class,
        ];
    }

    /**
     * Defaults to an in-memory SQLite database. Set DB_CONNECTION (and
     * DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD as needed) to
     * run the same suite against a real MySQL/Postgres server instead -
     * see .github/workflows/tests.yml, which does this in CI so the
     * non-SQLite branches of DetectsUniqueConstraintViolations actually
     * get exercised somewhere, not just read.
     */
    protected function defineEnvironment($app): void
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');

        if ($driver === 'sqlite') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);

            return;
        }

        $app['config']->set('database.connections.testing', [
            'driver' => $driver,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function fakeProvider(): FakePaymentProvider
    {
        return $this->app->make(FakePaymentProvider::class);
    }
}
