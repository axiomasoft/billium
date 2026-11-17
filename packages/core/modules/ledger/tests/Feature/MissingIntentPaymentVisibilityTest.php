<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * D60 (observation-apply 2/4, REQ-FAIL-12): a confirmed capture without a settlement intent (no authenticated account
 * mapping) is observed and stays queryable, while allocation parks: no allocatable payment exists, the partition is not
 * blocked, and after the intent is declared a dependency retry in its own root applies it exactly once.
 */
#[Group('D60')]
#[Group('pg')]
final class MissingIntentPaymentVisibilityTest extends ObservationTestCase
{
    public function test_an_observed_capture_without_intent_is_visible_parks_allocation_and_applies_after_declare(): void
    {
        $this->debt('o1', 1000);
        $charge = ObservationFactory::charge('op-1', 1, 1000, sequence: 1, fields: ['businessIntentRef' => 'intent-late']);

        self::assertSame(ProblemCode::ObservationParked->value, $this->tryObserve($charge));

        $observed = $this->observedRow();
        self::assertSame('succeeded', $observed['state']);
        self::assertSame(1000, self::integer($observed['captured_minor']), 'confirmed provider money is visible without allocation');
        self::assertNull($observed['billing_account_ref'], 'no account is guessed');
        self::assertSame(0, $this->rows('billium_payment'), 'no allocatable source until the account mapping exists');
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_observation_park', "dependency_kind = 'settlement_intent' AND dependency_ref = 'intent-late' AND released_at IS NULL"));
        self::assertSame('parked_dependency', $this->inboxStatus(self::eventId($charge)));
        self::assertSame(1, self::integer($this->value('SELECT accounted_sequence FROM billium_feed_partition')), 'the partition accounted the parked event');

        // An unrelated later sequence applies at once: the dependency-parked observation does not block the partition.
        $this->intent('intent-b', ['o1']);
        $other = $this->observe(ObservationFactory::charge('op-2', 1, 200, sequence: 2, fields: ['businessIntentRef' => 'intent-b']));
        self::assertSame('assign', $other->mode());

        self::assertSame([], $this->drainer()->retryParked(), 'nothing to retry while the dependency is missing');
        self::assertSame(1, $this->rows('billium_observation_park', 'released_at IS NULL'));

        $this->debt('o2', 1000);
        $this->intent('intent-late', ['o2']);
        self::assertSame(['applied'], $this->drainer()->retryParked());

        $payment = $this->paymentRow();
        self::assertSame('account-a', $payment['billing_account_ref']);
        self::assertSame(1000, self::integer($payment['captured_minor']));
        self::assertSame(1000, $this->allocated('o2'));
        self::assertSame('account-a', $this->observedRow()['billing_account_ref']);
        self::assertSame('applied', $this->inboxStatus(self::eventId($charge)));
        self::assertSame(0, $this->rows('billium_observation_park', 'released_at IS NULL'));
        self::assertSame([], $this->drainer()->retryParked());

        $replay = $this->observe($charge);
        self::assertSame('observation:embedded-commerz:op-1#1', $replay->receiptRef);
        self::assertSame(2, $this->rows('billium_allocation'), 'exactly one effect per payment');
    }

    public function test_a_later_revision_waits_behind_the_parked_earlier_one_and_follows_it(): void
    {
        $this->debt('o1', 1000);
        $first = ObservationFactory::charge('op-1', 1, 400, sequence: 1);
        $second = ObservationFactory::charge('op-1', 2, 1000, sequence: 2);

        self::assertSame(ProblemCode::ObservationParked->value, $this->tryObserve($first));
        self::assertSame(ProblemCode::ObservationParked->value, $this->tryObserve($second));
        self::assertSame(1, $this->rows('billium_observed_payment_revision'), 'the later revision is not applied out of order');

        $this->intent('intent-a', ['o1']);
        self::assertSame(['applied'], $this->drainer()->retryParked());

        self::assertSame('applied', $this->inboxStatus(self::eventId($first)));
        self::assertSame('applied', $this->inboxStatus(self::eventId($second)));
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame(2, self::integer($this->paymentRow()['applied_revision']));
    }
}
