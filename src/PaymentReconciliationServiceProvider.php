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

        // Registered unconditionally (not gated behind runningInConsole()):
        // $this->commands() only queues an Artisan::starting() callback, so
        // it's free outside a real console run, but gating it behind
        // runningInConsole() means the callback never gets queued at all
        // during a web request - so Artisan::call('payments:reconcile')
        // from application code (a controller, a queued job dispatched
        // from one) would fail with CommandNotFoundException even though
        // `php artisan payments:reconcile` and the scheduler both work
        // fine (both genuinely run under the CLI SAPI).
        $this->commands([
            ReconcilePayments::class,
            PaymentStatusCommand::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payment-reconciliation.php' => config_path('payment-reconciliation.php'),
            ], 'payment-reconciliation-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'payment-reconciliation-migrations');
        }
    }
}
