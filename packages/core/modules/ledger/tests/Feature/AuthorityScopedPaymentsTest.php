<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\ReallocatePayment;
use Billium\Ledger\Data\ReallocationMode;
use Billium\Ledger\Tests\Queries\QueryTestCase;
use Billium\Platform\Contracts\EventFeed;
use Billium\Platform\Data\Feed\FeedScope;

/** Same-ref sources must stay independent through commands, reads, snapshots and dependency retries. */
final class AuthorityScopedPaymentsTest extends QueryTestCase
{
    private const array SOURCES = ['embedded-commerz', 'remote-billium'];

    private function sources(): void
    {
        foreach (self::SOURCES as $index => $authority) {
            $suffix = (string) $index;
            $this->debt('o'.$suffix, 1000 + $index * 1000, 'account-'.$suffix);
            $this->intent('intent-'.$suffix, ['o'.$suffix], 'account-'.$suffix);
            $this->observe(ObservationFactory::charge('same-payment', 1, 1600 + $index * 1900,
                fields: ['sourceAuthority' => $authority, 'businessIntentRef' => 'intent-'.$suffix]));
        }
    }

    public function test_wire_refund_authorization_selects_each_authority_with_the_same_payment_ref(): void
    {
        $this->sources();
        foreach (self::SOURCES as $index => $authority) {
            foreach ([[], ['amount' => '16.00']] as $amount) {
                $wire = $this->queries()->dispatch(self::request('refundAuthorization',
                    ['paymentRef' => 'same-payment', 'sourceAuthority' => $authority, ...$amount]));
                $advice = self::arr($wire['result']);
                self::assertSame($index === 0 ? '16.00' : '35.00', self::arr($advice['normalCapacity'])['amount']);
                self::assertSame($amount['amount'] ?? ($index === 0 ? '16.00' : '35.00'), self::arr($advice['requested'])['amount']);
                self::assertSame($index === 0 ? '10.00' : ($amount === [] ? '20.00' : '1.00'), self::arr($advice['requiredReallocation'])['amount']);
                self::assertTrue($advice['allowed']);
            }
        }
        self::problem(ProblemCode::ValidationInvalid, fn () => $this->queries()->dispatch(
            self::request('refundAuthorization', ['paymentRef' => 'same-payment'])));
    }

    public function test_wire_refund_authorization_never_falls_back_to_another_authority(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('same-payment', 1, 1600));

        self::problem(ProblemCode::ReferenceUnknown, fn () => $this->queries()->dispatch(self::request('refundAuthorization',
            ['paymentRef' => 'same-payment', 'sourceAuthority' => 'remote-billium'])));
        $wire = $this->queries()->dispatch(self::request('refundAuthorization', ['paymentRef' => 'same-payment']));
        self::assertSame('16.00', self::arr(self::arr($wire['result'])['normalCapacity'])['amount']);
    }

    public function test_each_authority_reads_its_applied_projection_receipts_and_bootstrap_snapshot(): void
    {
        $this->sources();
        foreach (self::SOURCES as $index => $authority) {
            $status = $this->queries()->paymentStatus('same-payment', $authority);
            self::assertSame('account-'.$index, $status['billingAccountRef']);
            self::assertSame('current', $status['projectionStatus']);
            self::assertSame($index === 0 ? '6.00' : '15.00', self::arr($status['spendable'])['amount']);
            self::assertSame($index === 0 ? '10.00' : '20.00', self::arr(self::arr($status['applied'])['allocated'])['amount']);
            $receipts = self::arr($this->queries()->allocationByOperation('same-payment', $authority)['receipts']);
            self::assertCount(1, $receipts);
            $receipt = self::arr($receipts[0]);
            self::assertSame('account-'.$index, $receipt['billing_account_ref']);
            self::assertSame('observation:'.$authority.':same-payment#1', $receipt['receipt_ref']);
            $allocations = self::arr($receipt['allocations']);
            self::assertCount(1, $allocations);
            self::assertSame('o'.$index, self::arr($allocations[0])['obligation_ref']);
            self::assertSame($index === 0 ? '1000' : '2000', self::arr($allocations[0])['amount_minor']);
        }
        self::problem(ProblemCode::ValidationInvalid, fn () => $this->queries()->paymentStatus('same-payment'));
        self::problem(ProblemCode::ValidationInvalid, fn () => $this->queries()->allocationByOperation('same-payment'));

        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $feed = $this->application()->make(EventFeed::class);
        $selection = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1',
            ['billium.event.v1'], ['payment.outcome.v1']);
        $payments = array_column($feed->bootstrap($selection)->snapshots['ledger.payments'], 'payload', 'identity');
        foreach (self::SOURCES as $index => $authority) {
            $payment = self::arr($payments['payment:'.$authority.':same-payment']);
            self::assertSame('account-'.$index, $payment['billingAccountRef']);
            self::assertSame($index === 0 ? '6.00' : '15.00', self::arr($payment['spendable'])['amount']);
            self::assertCount(1, self::arr($payment['appliedRevisions']));
        }
    }

    public function test_allocations_and_reallocations_resolve_source_identity_and_replay_only_that_payment(): void
    {
        $this->sources();
        self::problem(ProblemCode::ValidationInvalid, fn () => $this->reallocate(ReallocationMode::Reversal, 'same-payment', 'ambiguous-reallocation'));
        $receipts = [];
        foreach (self::SOURCES as $index => $authority) {
            $this->ledger()->adjustObligation(new AdjustObligation('extra-debt-'.$index, $this->account('account-'.$index),
                'extra-'.$index, 'o'.$index, Money::parse($index === 0 ? '6.00' : '15.00', 'RUB'), 'extra-basis-'.$index))->orThrow();
            self::problem(ProblemCode::ValidationInvalid, fn () => $this->allocateFrom('ambiguous-'.$index, 'o'.$index,
                '1.00', 'same-payment', account: 'account-'.$index));
            $source = 'payment:'.$authority.':same-payment';
            $allocation = $this->allocateFrom('extra-'.$index, 'o'.$index, $index === 0 ? '6.00' : '15.00',
                $source, account: 'account-'.$index)->orThrow();
            self::assertSame($allocation->body, $this->allocateFrom('extra-'.$index, 'o'.$index, $index === 0 ? '6.00' : '15.00',
                $source, key: 'allocate-replay-'.$index, account: 'account-'.$index)->orThrow()->body);
            $this->observe(ObservationFactory::charge('same-payment', 2, 1600 + $index * 1900, $index === 0 ? 400 : 500, 2,
                ['sourceAuthority' => $authority, 'businessIntentRef' => 'intent-'.$index]));
        }
        self::assertSame(2, $this->rows('billium_payment', 'reallocation_required'));
        foreach (self::SOURCES as $index => $authority) {
            $command = new ReallocatePayment('reallocate-'.$index, self::INSTALLATION, 'seller-a', 'test',
                'payment:'.$authority.':same-payment', 'refund/1', ReallocationMode::Reversal);
            $receipt = $this->ledger()->reallocatePayment($command)->orThrow();
            $receipts[$index] = $receipt->body;
            self::assertSame($authority, $receipt->body['source_authority']);
            self::assertSame($index === 0 ? '400' : '500', $receipt->body['excess_minor']);
            self::assertSame(1 - $index, $this->rows('billium_payment', 'reallocation_required'));
        }
        $before = $this->table('SELECT id,allocated_minor,reallocation_required FROM billium_payment ORDER BY id');
        $journal = $this->rows('billium_allocation');
        foreach (self::SOURCES as $index => $authority) {
            $again = $this->ledger()->reallocatePayment(new ReallocatePayment('reallocate-replay-'.$index, self::INSTALLATION,
                'seller-a', 'test', 'payment:'.$authority.':same-payment', 'refund/1', ReallocationMode::Reversal))->orThrow();
            self::assertTrue($again->accepted);
            self::assertSame($receipts[$index], $again->body);
        }
        self::assertSame($before, $this->table('SELECT id,allocated_minor,reallocation_required FROM billium_payment ORDER BY id'));
        self::assertSame($journal, $this->rows('billium_allocation'));
    }

    public function test_zero_part_receipts_are_source_bound_even_for_the_same_account(): void
    {
        $this->debt('o1', 100);
        $this->intent('intent-a', ['o1']);
        foreach (self::SOURCES as $index => $authority) {
            $this->observe(ObservationFactory::charge('same-payment', 1, $index === 0 ? 100 : 200, fields: ['sourceAuthority' => $authority]));
        }
        foreach (self::SOURCES as $authority) {
            $receipts = self::arr($this->queries()->allocationByOperation('same-payment', $authority)['receipts']);
            self::assertCount(1, $receipts);
            self::assertSame('observation:'.$authority.':same-payment#1', self::arr($receipts[0])['receipt_ref']);
        }
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_allocation_receipt(installation_ref,seller_ref,environment,billing_account_ref,currency,receipt_ref,kind,semantic_digest,body)
            SELECT installation_ref,seller_ref,environment,billing_account_ref,currency,'observation:same-payment#1',kind,semantic_digest,
                body || jsonb_build_object('receipt_ref','observation:same-payment#1')
            FROM billium_allocation_receipt WHERE receipt_ref='observation:remote-billium:same-payment#1'
            SQL);
        self::assertCount(1, self::arr($this->queries()->allocationByOperation('same-payment', 'embedded-commerz')['receipts']));
        $legacy = self::arr($this->queries()->allocationByOperation('same-payment', 'remote-billium')['receipts']);
        self::assertSame(['observation:remote-billium:same-payment#1', 'observation:same-payment#1'], array_column($legacy, 'receipt_ref'));
    }

    public function test_another_authoritys_original_charge_never_consumes_a_parked_refunds_retry_budget(): void
    {
        config(['billium.inbox.retry_backoff_seconds' => 0]);
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('same-payment', 1, 500));
        $refund = ObservationFactory::refund('refund-b', 'same-payment', 1, 100, fields: ['sourceAuthority' => 'remote-billium']);
        self::assertSame(ProblemCode::ObservationParked->value, $this->attempt($refund));
        $attempts = $this->value('SELECT attempts FROM billium_inbox_message WHERE external_event_id=?', [self::eventId($refund)]);
        for ($i = 0; $i < 15; $i++) {
            self::assertSame([], $this->drainer()->retryParked());
        }
        self::assertSame($attempts, $this->value('SELECT attempts FROM billium_inbox_message WHERE external_event_id=?', [self::eventId($refund)]));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='refund_detail'"));

        $this->observe(ObservationFactory::charge('same-payment', 1, 500, sequence: 2, fields: ['sourceAuthority' => 'remote-billium']));
        self::assertSame(['applied'], $this->drainer()->retryParked());
        self::assertSame('applied', $this->inboxStatus(self::eventId($refund)));
        self::assertSame(0, $this->rows('billium_observation_park', 'released_at IS NULL'));
        self::assertSame('refund-detail:remote-billium:refund-b#1', $this->value("SELECT receipt_ref FROM billium_allocation_receipt WHERE kind='refund_detail'"));
        self::assertSame('account-a', $this->value("SELECT billing_account_ref FROM billium_observed_payment WHERE operation_ref='refund-b' AND source_authority='remote-billium'"));
    }
}
