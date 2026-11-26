<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Data\Money;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Usage\Contracts\Usage;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4EmbeddedAllocationTest extends CompositionTestCase
{
    protected function modules(): array
    {
        return ['platform', 'ledger'];
    }

    public function test_embedded_ledger_allocates_durable_credit_without_metering_rating_or_schedule(): void
    {
        $app = $this->application();
        foreach ([Usage::class, RatingPreparer::class, OccurrenceService::class] as $port) {
            self::assertFalse($app->bound($port));
        }
        foreach (['billium_usage_fact', 'billium_rating_rated_basis', 'billium_schedule_period', 'billium_schedule_billing_occurrence'] as $table) {
            self::assertNull($this->owner()->scalar("SELECT to_regclass('public.{$table}')::text"));
        }
        self::assertSame(['ledger', 'platform'], $this->owner()->table('billium_module_state')->orderBy('module_id')->pluck('module_id')->all());

        $ledger = $app->make(Ledger::class);
        $scope = new BillingAccountScope(sellerRef: 'seller-a', environment: 'test', billingAccountRef: 'embedded-account');
        $ledger->recordObligation(new RecordObligation(commandKey: 'embedded-debt', scope: $scope,
            obligationRef: 'embedded-obligation', basisRef: 'host:approved-basis', customerRef: 'customer-a', componentRef: 'component-a',
            money: Money::parse('49.00', 'USD'), termsDigest: 'sha256:'.str_repeat('a', 64)))->orThrow();
        $ledger->recordCustomerCredit(new RecordCustomerCredit(commandKey: 'embedded-credit', scope: $scope,
            creditRef: 'embedded-credit', sourceRef: 'host:prepayment', money: Money::parse('60.00', 'USD')))->orThrow();
        $command = new Allocate(commandKey: 'embedded-allocate', scope: $scope, allocationRef: 'embedded-allocation',
            obligationRef: 'embedded-obligation', money: Money::parse('49.00', 'USD'), customerCreditRef: 'embedded-credit');
        $first = $ledger->allocate($command)->orThrow();
        self::assertSame('ledger.allocation', $first->receiptClass);
        self::assertSame($first->body, $ledger->allocate($command)->orThrow()->body);
        self::assertSame($first->body, $ledger->allocate(new Allocate(commandKey: 'embedded-retry', scope: $scope,
            allocationRef: 'embedded-allocation', obligationRef: 'embedded-obligation', money: Money::parse('49.00', 'USD'),
            customerCreditRef: 'embedded-credit'))->orThrow()->body);

        // Read committed rows through the distinct owner connection, not an in-memory command result.
        self::assertSame(1, $this->owner()->table('billium_allocation')->count());
        self::assertSame(4900, $this->owner()->table('billium_allocation')->value('amount_minor'));
        self::assertSame(4900, $this->owner()->table('billium_obligation')->value('allocated_minor'));
        self::assertSame(0, $this->owner()->table('billium_obligation')->value('open_minor'));
        self::assertSame('settled', $this->owner()->table('billium_obligation')->value('status'));
        self::assertSame(1100, $this->owner()->table('billium_customer_credit')->value('remaining_minor'));
        self::assertSame(1, $this->owner()->table('billium_outbox_event')->where('type', 'allocation.applied.v1')->count());
        self::assertSame(0, $this->owner()->table('billium_payment')->count());
    }
}
