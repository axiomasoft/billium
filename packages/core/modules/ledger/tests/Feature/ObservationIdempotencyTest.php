<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ObservationIntake;
use Billium\Ledger\Tests\Observation\FailingHandler;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * A redelivered observation returns the original receipt with exactly one
 * allocation; a failure after the allocation but before the inbox disposition rolls back the observed fact, projection,
 * allocation, receipt, outbox and custody together, and the retry has exactly one effect.
 */
#[Group('pg')]
final class ObservationIdempotencyTest extends ObservationTestCase
{
    #[Group('PB-14')]
    public function test_a_duplicate_observation_returns_the_same_receipt_and_one_allocation(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $charge = ObservationFactory::charge('op-1', 1, 10000);

        $first = $this->observe($charge);
        $again = $this->observe($charge);
        // Another authenticated delivery of the same material snapshot: evidence only, no second allocation.
        $redelivery = ObservationFactory::with($charge, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 2, 'evidenceRef' => 'ev-other',
            'recordedAt' => '2026-10-29T11:00:00Z', 'providerOperationId' => 'psp-77']);
        $other = $this->observe($redelivery);

        self::assertSame('observation:embedded-commerz:op-1#1', $first->receiptRef);
        self::assertFalse($first->replayed);
        self::assertSame($first->receiptRef, $again->receiptRef);
        self::assertTrue($again->replayed);
        self::assertSame($first->body, $again->body);
        self::assertSame($first->receiptRef, $other->receiptRef);
        self::assertSame($first->body, $other->body);
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(10000, $this->allocated('o1'));
        self::assertSame(10000, self::integer($this->paymentRow()['allocated_minor']));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind = 'payment_observation'"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
        self::assertSame(1, $this->rows('billium_observed_payment_revision'));
        self::assertSame('applied', $this->inboxStatus(self::eventId($redelivery)));
    }

    #[Group('PF-12')]
    public function test_a_failure_after_allocation_rolls_back_everything_and_the_retry_applies_once(): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $charge = ObservationFactory::charge('op-1', 1, 6000);
        $failing = new FailingHandler($this->application()->make(ObservationHandler::class));
        $intake = $this->application()->make(ObservationIntake::class, ['handler' => $failing]);

        try {
            $intake->apply(ObservationFactory::json($charge));
            self::fail('the injected failure must surface');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('injected failure', $failure->getMessage());
        }

        self::assertSame(1, $failing->calls, 'the economic apply ran (allocation written) before the failure');
        foreach (['billium_observed_payment', 'billium_observed_payment_revision', 'billium_payment', 'billium_payment_revision',
            'billium_allocation', 'billium_observation_park', 'billium_ledger_discrepancy'] as $table) {
            self::assertSame(0, $this->rows($table), $table.' rolled back');
        }
        // Raw custody committed in its own root before the economic root; it is not ProjectionApplied.
        self::assertSame('ready', $this->inboxStatus(self::eventId($charge)));
        self::assertSame(0, self::integer($this->partition()['accounted_sequence']), 'the failed economic root advanced nothing');
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind = 'payment_observation'"));
        self::assertSame(0, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
        self::assertSame(0, $this->allocated('o1'));

        $retry = $this->observe($charge);
        $replay = $this->observe($charge);

        self::assertSame('observation:embedded-commerz:op-1#1', $retry->receiptRef);
        self::assertSame($retry->receiptRef, $replay->receiptRef);
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(6000, $this->allocated('o1'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
        self::assertSame('applied', $this->inboxStatus(self::eventId($charge)));
    }
}
