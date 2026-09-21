<?php

declare(strict_types=1);

namespace VendorName\LaravelPaymentReconciliation\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use VendorName\LaravelPaymentReconciliation\Enums\PaymentStatus;
use VendorName\LaravelPaymentReconciliation\Models\Payment;
use VendorName\LaravelPaymentReconciliation\Providers\ProviderRegistry;
use VendorName\LaravelPaymentReconciliation\Reconciliation\ReconciliationService;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
        {--provider= : Only reconcile payments for this provider}
        {--status= : Only reconcile payments currently in this local status}
        {--payment= : Only reconcile the payment with this UUID}';

    protected $description = 'Reconcile local payment state against provider state.';

    public function __construct(
        private readonly ReconciliationService $reconciliationService,
        private readonly ProviderRegistry $providers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Payment::query();

        if ($paymentId = $this->option('payment')) {
            $query->whereKey($paymentId);
        } else {
            if ($provider = $this->option('provider')) {
                $query->where('provider', $provider);
            }

            if ($status = $this->option('status')) {
                $status = PaymentStatus::tryFrom($status);

                if ($status === null) {
                    $this->error(sprintf(
                        'Invalid --status. Valid values: %s',
                        implode(', ', array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases()))
                    ));

                    return self::FAILURE;
                }

                $query->where('status', $status->value);
            } else {
                // Default to payments that can still change - reconciling
                // an already-terminal payment on every scheduled run would
                // be an unbounded, pointless scan as the table grows.
                $query->whereNotIn('status', array_map(
                    fn (PaymentStatus $s) => $s->value,
                    PaymentStatus::terminal()
                ));
            }
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($payments) use (&$count) {
            foreach ($payments as $payment) {
                try {
                    $provider = $this->providers->resolve($payment->provider);
                } catch (InvalidArgumentException $e) {
                    $this->warn($e->getMessage());

                    continue;
                }

                $result = $this->reconciliationService->reconcile($payment, $provider);
                $count++;

                $this->line(sprintf('[%s] %s: %s', $payment->id, $payment->provider, $result->type->value));
            }
        });

        $this->info("Reconciled {$count} payment(s).");

        return self::SUCCESS;
    }
}
