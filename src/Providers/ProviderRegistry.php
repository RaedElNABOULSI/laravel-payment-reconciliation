<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Providers;

use InvalidArgumentException;
use Raedev\LaravelPaymentReconciliation\Contracts\PaymentProvider;

/**
 * Resolves the configured PaymentProvider implementation for a given
 * provider name (e.g. "stripe", "fake"), based on the `providers` map
 * in config/payment-reconciliation.php.
 */
class ProviderRegistry
{
    public function resolve(string $name): PaymentProvider
    {
        /** @var array<string, class-string> $map */
        $map = config('payment-reconciliation.providers', []);

        if (! isset($map[$name])) {
            throw new InvalidArgumentException(
                "No payment provider is registered for \"{$name}\". Add it to the \"providers\" array in config/payment-reconciliation.php."
            );
        }

        $provider = app($map[$name]);

        if (! $provider instanceof PaymentProvider) {
            throw new InvalidArgumentException(
                "The class registered for provider \"{$name}\" (".$map[$name].') does not implement '.PaymentProvider::class.'.'
            );
        }

        return $provider;
    }
}
