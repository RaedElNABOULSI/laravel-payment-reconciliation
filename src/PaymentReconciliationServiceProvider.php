<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation;

use Illuminate\Support\ServiceProvider;
use VendorName\LaravelPaymentReconciliation\Console\Commands\PaymentStatusCommand;
use VendorName\LaravelPaymentReconciliation\Console\Commands\ReconcilePayments;
use VendorName\LaravelPaymentReconciliation\Contracts\PaymentProvider;
use VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider;
use VendorName\LaravelPaymentReconciliation\Providers\ProviderRegistry;

class PaymentReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-reconciliation.php', 'payment-reconciliation');

        // Singleton so state simulated via setProviderStatus()/simulateUnavailable()
        // persists for the lifetime of a request, test, or console command.
        $this->app->singleton(FakePaymentProvider::class);

        $this->app->bind(PaymentProvider::class, function ($app) {
            return $app->make(ProviderRegistry::class)
                ->resolve($app->make('config')->get('payment-reconciliation.default_provider'));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payment-reconciliation.php' => config_path('payment-reconciliation.php'),
            ], 'payment-reconciliation-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'payment-reconciliation-migrations');

            $this->commands([
                ReconcilePayments::class,
                PaymentStatusCommand::class,
            ]);
        }
    }
}
