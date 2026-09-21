<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Providers;

use InvalidArgumentException;
use VendorName\LaravelPaymentReconciliation\Contracts\PaymentProvider;

/**
 * Resolves the configured PaymentProvider implementation for a given
 * provider name (e.g. "stripe", "fake"), based on the `providers` map
 * in config/payment-reconciliation.php.
 */
class ProviderRegistry
{
    public function resolve(string $name): PaymentProvider
    {
        $map = config('payment-reconciliation.providers', []);

        if (! isset($map[$name])) {
            throw new InvalidArgumentException(
                "No payment provider is registered for \"{$name}\". Add it to the \"providers\" array in config/payment-reconciliation.php."
            );
        }

        return app($map[$name]);
    }
}
