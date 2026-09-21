<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Raedev\LaravelPaymentReconciliation\Enums\PaymentStatus;
use Raedev\LaravelPaymentReconciliation\Models\Payment;
use Raedev\LaravelPaymentReconciliation\Providers\ProviderRegistry;
use Raedev\LaravelPaymentReconciliation\Reconciliation\ReconciliationService;

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

        $paymentId = $this->option('payment');

        if (is_string($paymentId) && $paymentId !== '') {
            $query->whereKey($paymentId);
        } else {
            $provider = $this->option('provider');

            if (is_string($provider) && $provider !== '') {
                $query->where('provider', $provider);
            }

            $statusOption = $this->option('status');

            if (is_string($statusOption) && $statusOption !== '') {
                $status = PaymentStatus::tryFrom($statusOption);

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
                // whereIn (not whereNotIn) on the small set of non-terminal
                // statuses so the `status` index can actually be used to
                // skip terminal rows, instead of forcing a full scan the
                // way a NOT IN predicate typically does.
                $nonTerminal = array_filter(PaymentStatus::cases(), fn (PaymentStatus $s) => ! $s->isTerminal());

                $query->whereIn('status', array_map(fn (PaymentStatus $s) => $s->value, $nonTerminal));
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
