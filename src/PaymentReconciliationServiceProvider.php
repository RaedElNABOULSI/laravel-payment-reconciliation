<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Raedev\LaravelPaymentReconciliation\Console\Commands\PaymentStatusCommand;
use Raedev\LaravelPaymentReconciliation\Console\Commands\ReconcilePayments;
use Raedev\LaravelPaymentReconciliation\Contracts\PaymentProvider;
use Raedev\LaravelPaymentReconciliation\Providers\FakePaymentProvider;
use Raedev\LaravelPaymentReconciliation\Providers\ProviderRegistry;

class PaymentReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-reconciliation.php', 'payment-reconciliation');

        // Singleton so state simulated via setProviderStatus()/simulateUnavailable()
        // persists for the lifetime of a request, test, or console command.
        $this->app->singleton(FakePaymentProvider::class);

        $this->app->bind(PaymentProvider::class, function (Application $app) {
            /** @var Repository $config */
            $config = $app->make('config');

            $defaultProvider = $config->get('payment-reconciliation.default_provider');

            return $app->make(ProviderRegistry::class)
                ->resolve(is_string($defaultProvider) ? $defaultProvider : 'fake');
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
