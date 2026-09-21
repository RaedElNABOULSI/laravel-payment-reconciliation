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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
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
