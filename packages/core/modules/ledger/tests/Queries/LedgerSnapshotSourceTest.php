<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Queries;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Snapshot\LedgerSnapshotSource;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Data\Feed\FeedScope;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
// Feed reads stop at the cluster-wide snapshot xmin, which transactions in other databases hold back.
#[Group('pg-serial')]
final class LedgerSnapshotSourceTest extends QueryTestCase
{
    public function test_snapshot_keeps_pending_actual_refund_children_and_discrepancy_evidence(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 10000));
        $this->observe(ObservationFactory::refund('refund-1', 'op-1', 1, 2000, sequence: 2));
        $this->tryObserve(ObservationFactory::charge('unbound', 1, 1234, sequence: 3, fields: ['businessIntentRef' => 'missing']));
        $this->observe(ObservationFactory::charge('op-1', 2, 10000, 14000, sequence: 4));
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $feed = $this->application()->make(EventFeed::class);
        $selection = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], ['payment.outcome.v1', 'discrepancy.recorded.v1']);
        $snapshot = $feed->bootstrap($selection);
        $payments = array_column($snapshot->snapshots['ledger.payments'], 'payload', 'identity');
        $pending = self::arr($payments['payment:embedded-commerz:unbound']);
        self::assertArrayNotHasKey('applied', $pending);
        self::assertSame('12.34', self::arr(self::arr($pending['observed'])['capturedTotal'])['amount']);
        $refund = self::arr($payments['refund:embedded-commerz:refund-1']);
        self::assertSame('op-1', $refund['original_ref']);
        self::assertSame('account-a', $refund['billing_account_ref']);
        self::assertCount(1, self::arr($refund['observedRevisions']));
        $held = self::arr($payments['payment:embedded-commerz:op-1']);
        self::assertTrue(self::arr($held['financialHold'])['active']);
        self::assertSame('140.00', self::arr(self::arr($held['observed'])['refundedTotal'])['amount']);
        self::assertSame('0.00', self::arr(self::arr($held['applied'])['refundedTotal'])['amount']);
        self::assertCount(1, $snapshot->snapshots['ledger.discrepancies']);
        $discrepancy = self::arr($snapshot->snapshots['ledger.discrepancies'][0]['payload']);
        self::assertSame('140.00', self::arr($discrepancy['actual'])['amount']);
        self::assertSame('op-1', $discrepancy['operationRef']);
    }

    public function test_feed_bootstrap_contains_ledger_state_receipts_and_stable_revisions_then_tails(): void
    {
        $this->seedQueryState();
        $this->creditNote('note-a', ['obligation-a' => '10.00'])->orThrow();
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $feed = $this->application()->make(EventFeed::class);
        $types = ['obligation.created.v1', 'invoice.issued.v1', 'allocation.applied.v1', 'payment.outcome.v1', 'discrepancy.recorded.v1'];
        $selection = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], $types);
        $before = [$this->rows('billium_allocation'), $this->rows('billium_allocation_receipt'), $this->rows('billium_outbox_event')];
        $snapshot = $feed->bootstrap($selection);
        $keys = array_keys($snapshot->snapshots);
        sort($keys);
        $expected = LedgerSnapshotSource::KEYS;
        sort($expected);
        self::assertSame($expected, $keys);
        $obligations = array_column($snapshot->snapshots['ledger.obligations'], 'payload', 'identity');
        self::assertCount(1, self::arr(self::arr($obligations['obligation:obligation-a'])['creditNotes']));
        $repeat = $feed->bootstrap($selection);
        self::assertSame((new Codec)->canonicalize($snapshot->snapshots), (new Codec)->canonicalize($repeat->snapshots), 'Same economic state produces stable identities, revisions and digests');
        foreach ($snapshot->snapshots as $records) {
            foreach ($records as $record) {
                self::assertSame((new Codec)->digest($record['payload']), $record['digest']);
            }
        }
        $payments = array_column($snapshot->snapshots['ledger.payments'], 'payload', 'identity');
        $status = self::arr($payments['payment:embedded-commerz:op-1']);
        self::assertSame('account-a', $status['billingAccountRef']);
        self::assertCount(1, self::arr($status['appliedRevisions']));
        self::assertCount(1, self::arr($status['observedRevisions']));
        self::assertCount(2, self::arr($status['allocations']));
        self::assertSame($before, [$this->rows('billium_allocation'), $this->rows('billium_allocation_receipt'), $this->rows('billium_outbox_event')]);
        self::assertSame('active', $this->value('SELECT state FROM billium_feed_bootstrap_lease WHERE lease_ref=?', [$snapshot->leaseRef]));
        $this->debt('tail-obligation', 1000);
        $feed->acknowledgeBootstrap($selection, $snapshot->leaseRef, $snapshot->snapshotDigest);
        $tail = $feed->read($selection, $snapshot->cursor->token);
        self::assertCount(1, $tail->events);
    }

    #[Group('PB-23')]
    public function test_a_refund_only_revision_after_bootstrap_emits_one_payment_event_and_replay_emits_none(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 10000, sequence: 1));
        self::assertSame(10000, $this->allocated('o1'));
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $feed = $this->application()->make(EventFeed::class);
        $selection = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'),
            'events-2026-10-v1', ['billium.event.v1'], ['payment.outcome.v1']);
        $snapshot = $feed->bootstrap($selection);
        $feed->acknowledgeBootstrap($selection, $snapshot->leaseRef, $snapshot->snapshotDigest);
        self::assertCount(0, $feed->read($selection, $snapshot->cursor->token)->events);
        $allocationEvents = $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'");

        $refund = ObservationFactory::charge('op-1', 2, 10000, 3000, sequence: 2);
        $receipt = $this->observe($refund);
        self::assertSame('assign', $receipt->mode());
        self::assertSame([], $receipt->body['allocations']);
        self::assertNull($receipt->body['discrepancy']);
        self::assertTrue($receipt->body['reallocation_required']);
        self::assertSame(3000, self::integer($this->paymentRow()['refunded_minor']));
        self::assertSame($allocationEvents, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
        $tail = $feed->read($selection, $snapshot->cursor->token);
        self::assertCount(1, $tail->events);
        self::assertSame(['payment.outcome.v1', 'op-1', 2], [$tail->events[0]['eventType'], $tail->events[0]['aggregateRef'], $tail->events[0]['revision']]);
        self::assertSame((new Codec)->canonicalize($receipt->body), (new Codec)->canonicalize($tail->events[0]['payload']));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'payment.outcome.v1' AND aggregate_revision = 2"));

        self::assertSame($receipt->body, $this->observe($refund)->body, 'delivery replay');
        $replay = $this->observe(ObservationFactory::with($refund, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 3]));
        self::assertSame($receipt->receiptRef, $replay->receiptRef, 'economic replay under a new delivery');
        self::assertSame($receipt->body, $replay->body);
        self::assertSame((new Codec)->canonicalize($tail->events), (new Codec)->canonicalize($feed->read($selection, $snapshot->cursor->token)->events));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'payment.outcome.v1' AND aggregate_revision = 2"));
    }

    public function test_source_requires_feed_read_only_transaction_and_authenticated_scope(): void
    {
        $this->obligation();
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $feed = $this->application()->make(EventFeed::class);
        $selection = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], ['obligation.created.v1']);
        $source = $this->application()->make('billium.snapshot.ledger.obligations');
        self::assertInstanceOf(LedgerSnapshotSource::class, $source);
        try {
            $source->snapshot($selection, 'pgsql');
            self::fail('Snapshot outside the feed transaction must fail');
        } catch (LogicException $error) {
            self::assertStringContainsString('READ ONLY', $error->getMessage());
        }
        $this->callers->set(new Caller('seller-b', 'test', self::INSTALLATION, 'actor'));
        self::problem(ProblemCode::AuthDenied, fn () => $source->snapshot($selection, 'pgsql'));
    }
}
