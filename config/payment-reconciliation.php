<?php

declare(strict_types=1);

use VendorName\LaravelPaymentReconciliation\Providers\FakePaymentProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The provider name used when your application code doesn't specify one
    | explicitly. Must be a key present in the "providers" map below.
    |
    */

    'default_provider' => env('PAYMENT_RECONCILIATION_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Maps a provider name (as stored on the `provider` column of a payment)
    | to the PaymentProvider implementation that talks to it. Register your
    | own adapters here - see the "Extension/custom providers" section of
    | the README.
    |
    */

    'providers' => [
        'fake' => FakePaymentProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Override these if the default table names conflict with existing
    | tables in your application.
    |
    */

    'table_names' => [
        'payments' => 'payments',
        'webhook_events' => 'webhook_events',
    ],

];
