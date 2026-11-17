<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\OverRefundVector;
use Billium\Ledger\Tests\Queries\QueryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class PaymentProjectionQueryTest extends QueryTestCase
{
    use OverRefundVector;

    public function test_first_overcapture_keeps_actual_and_hold_without_inventing_applied(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 12000, fields: ['requested' => 10000, 'authorizedTotal' => 10000]));
        $status = $this->queries()->paymentStatus('op-1');
        self::assertArrayNotHasKey('applied', $status);
        self::assertSame('120.00', self::arr(self::arr($status['observed'])['capturedTotal'])['amount']);
        self::assertSame('20.00', self::arr(self::arr($status['exposure'])['overCaptured'])['amount']);
        self::assertTrue(self::arr($status['financialHold'])['active']);
        self::assertSame('0.00', self::arr($status['spendable'])['amount']);
        (new Codec)->decode(json_encode($this->queries()->dispatch(self::request('paymentStatus', ['paymentRef' => 'op-1'])), JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string}> */
    public static function profiles(): iterable
    {
        foreach (self::AUTHORITIES as $authority) {
            yield $authority => [$authority];
        }
    }

    #[DataProvider('profiles')]
    public function test_actual_over_refund_is_visible_with_last_consistent_applied_in_every_profile(string $authority): void
    {
        $this->overRefund($authority);
        $status = $this->queries()->paymentStatus('op-1');
        $applied = self::arr($status['applied']);
        $observed = self::arr($status['observed']);
        self::assertSame('100.00', self::arr($applied['capturedTotal'])['amount']);
        self::assertSame('70.00', self::arr($applied['refundedTotal'])['amount']);
        self::assertSame('140.00', self::arr($observed['refundedTotal'])['amount']);
        self::assertSame('40.00', self::arr(self::arr($status['exposure'])['overRefunded'])['amount']);
        self::assertSame(2, $applied['revision']);
        self::assertSame(3, $observed['revision']);
        self::assertTrue(self::arr($status['financialHold'])['active']);
        self::assertContains('over_refund', self::arr(self::arr($status['financialHold'])['reasonCodes']));
        self::assertSame('0.00', self::arr($status['spendable'])['amount']);
        self::assertSame('held_discrepancy', $status['projectionStatus']);
        self::assertFalse($this->queries()->refundAuthorization('op-1', Money::parse('1.00', 'RUB'))['allowed']);
        (new Codec)->decode(json_encode($this->queries()->dispatch(self::request('paymentStatus', ['paymentRef' => 'op-1'])), JSON_THROW_ON_ERROR));
    }

    public function test_missing_intent_exposes_capture_without_applied_or_spendable_and_query_does_not_drain(): void
    {
        self::assertSame(ProblemCode::ObservationParked->value, $this->tryObserve(ObservationFactory::charge('unbound', 1, 1234)));
        $counts = [$this->rows('billium_allocation_receipt'), $this->rows('billium_allocation'), $this->rows('billium_inbox_message'), $this->rows('billium_observation_park')];
        $status = $this->queries()->paymentStatus('unbound');
        self::assertArrayNotHasKey('applied', $status);
        self::assertArrayNotHasKey('billingAccountRef', $status);
        self::assertSame('12.34', self::arr(self::arr($status['observed'])['capturedTotal'])['amount']);
        self::assertSame('0.00', self::arr($status['spendable'])['amount']);
        self::assertSame('allocation_pending', $status['projectionStatus']);
        self::assertSame('settlement_intent', $status['pendingAllocationReason']);
        self::assertSame([], $this->queries()->allocationByOperation('unbound')['receipts']);
        self::assertFalse($this->queries()->refundAuthorization('unbound')['allowed']);
        self::assertSame($counts, [$this->rows('billium_allocation_receipt'), $this->rows('billium_allocation'), $this->rows('billium_inbox_message'), $this->rows('billium_observation_park')]);
        (new Codec)->decode(json_encode($this->queries()->dispatch(self::request('paymentStatus', ['paymentRef' => 'unbound'])), JSON_THROW_ON_ERROR));
    }

    public function test_pending_and_unknown_are_never_rendered_as_success(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 0, fields: ['requested' => 10000, 'state' => 'pending']));
        self::assertSame('pending', self::arr($this->queries()->paymentStatus('op-1')['applied'])['state']);
        self::assertCount(1, self::arr($this->queries()->allocationByOperation('op-1')['receipts']), 'No allocated parts does not erase the applied business receipt');
        $this->observe(ObservationFactory::charge('op-1', 2, 0, sequence: 2, fields: ['requested' => 10000, 'state' => 'accepted-unknown']));
        self::assertSame('accepted-unknown', self::arr($this->queries()->paymentStatus('op-1')['applied'])['state']);
    }

    public function test_recovery_and_bootstrap_are_visible_without_query_mutation(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000));
        $this->tryObserve(ObservationFactory::charge('op-1', 2, 2000, sequence: 2, fields: ['sourceLineage' => 'L2']));
        self::assertSame('recovery_required', $this->queries()->paymentStatus('op-1')['projectionStatus']);
        self::assertSame('0.00', self::arr($this->queries()->paymentStatus('op-1')['spendable'])['amount']);
        $this->bootstrapper()->execute($this->snapshot('boot', 'L2', 20, [ObservationFactory::charge('op-1', 2, 2000, fields: ['sourceLineage' => 'L2'])]));
        self::assertSame('adopted', self::arr($this->queries()->paymentStatus('op-1')['bootstrap'])['status']);
        self::assertSame('current', $this->queries()->paymentStatus('op-1')['projectionStatus']);
    }
}
