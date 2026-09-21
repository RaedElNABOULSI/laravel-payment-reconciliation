<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Unit;

use Raedev\LaravelPaymentReconciliation\Enums\PaymentStatus;
use Raedev\LaravelPaymentReconciliation\Services\PaymentStateMachine;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

class PaymentStateMachineTest extends TestCase
{
    private PaymentStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new PaymentStateMachine;
    }

    /**
     * @return array<string, array{0: PaymentStatus, 1: PaymentStatus}>
     */
    public static function validTransitions(): array
    {
        return [
            'pending -> processing' => [PaymentStatus::Pending, PaymentStatus::Processing],
            'pending -> cancelled' => [PaymentStatus::Pending, PaymentStatus::Cancelled],
            'processing -> paid' => [PaymentStatus::Processing, PaymentStatus::Paid],
            'processing -> failed' => [PaymentStatus::Processing, PaymentStatus::Failed],
            'processing -> unknown' => [PaymentStatus::Processing, PaymentStatus::Unknown],
            'unknown -> paid' => [PaymentStatus::Unknown, PaymentStatus::Paid],
            'unknown -> failed' => [PaymentStatus::Unknown, PaymentStatus::Failed],
        ];
    }

    /** @dataProvider validTransitions */
    public function test_allows_valid_transitions(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertTrue($this->machine->can($from, $to));
    }

    /**
     * @return array<string, array{0: PaymentStatus, 1: PaymentStatus}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'paid -> pending' => [PaymentStatus::Paid, PaymentStatus::Pending],
            'paid -> failed' => [PaymentStatus::Paid, PaymentStatus::Failed],
            'paid -> processing' => [PaymentStatus::Paid, PaymentStatus::Processing],
            'cancelled -> paid' => [PaymentStatus::Cancelled, PaymentStatus::Paid],
            'failed -> paid' => [PaymentStatus::Failed, PaymentStatus::Paid],
            'failed -> processing' => [PaymentStatus::Failed, PaymentStatus::Processing],
            'pending -> paid' => [PaymentStatus::Pending, PaymentStatus::Paid],
            'pending -> failed' => [PaymentStatus::Pending, PaymentStatus::Failed],
            'pending -> unknown' => [PaymentStatus::Pending, PaymentStatus::Unknown],
            'unknown -> processing' => [PaymentStatus::Unknown, PaymentStatus::Processing],
            'unknown -> pending' => [PaymentStatus::Unknown, PaymentStatus::Pending],
            'same status' => [PaymentStatus::Pending, PaymentStatus::Pending],
        ];
    }

    /** @dataProvider invalidTransitions */
    public function test_rejects_invalid_transitions(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertFalse($this->machine->can($from, $to));
    }

    public function test_terminal_statuses_have_no_allowed_transitions(): void
    {
        foreach (PaymentStatus::terminal() as $status) {
            $this->assertSame([], $this->machine->allowedFrom($status));
            $this->assertTrue($status->isTerminal());
        }
    }
}
