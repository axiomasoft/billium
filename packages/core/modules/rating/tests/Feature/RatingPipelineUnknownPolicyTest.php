<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Tests\RatingTestCase;

final class RatingPipelineUnknownPolicyTest extends RatingTestCase
{
    public function test_missing_price_and_tax_hold_without_zero_or_financial_rows(): void
    {
        $preparer = $this->application()->make(RatingPreparer::class);
        $missing = $preparer->prepare($this->request(priceVersion: 999));
        self::assertInstanceOf(RatingHold::class, $missing);
        self::assertSame(HoldReason::MissingPrice, $missing->reason);
        $this->tax->missing = true;
        $missing = $preparer->prepare($this->request());
        self::assertInstanceOf(RatingHold::class, $missing);
        self::assertSame(HoldReason::MissingTax, $missing->reason);
        self::assertSame(array_fill_keys(array_keys($this->ratingCounts()), 0), $this->ratingCounts());
    }

    public function test_incomplete_source_and_erased_aggregate_hold_instead_of_zero(): void
    {
        $this->completeness(status: 'gap', sequence: 2, repair: 'replay:gap');
        $held = $this->application()->make(RatingPreparer::class)->prepare($this->request());
        self::assertInstanceOf(RatingHold::class, $held);
        self::assertSame(HoldReason::IncompleteSource, $held->reason);
        $id = $this->value('SELECT id FROM billium_usage_aggregate_snapshot');
        $this->owner()->table('billium_usage_aggregate_reproducibility_event')->insert(['snapshot_id' => $id, 'reproducibility' => 'erased']);
        $held = $this->application()->make(RatingPreparer::class)->prepare($this->request());
        self::assertInstanceOf(RatingHold::class, $held);
        self::assertSame(HoldReason::AggregateNotReproducible, $held->reason);
        self::assertSame(0, $this->rows('billium_rating_rated_basis'));
    }

    public function test_explicit_approved_zero_has_a_reason_and_no_obligation(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '0'), commandKey: 'zero-price');
        $receipt = $this->book($this->prepared(priceVersion: 2, noCharge: 'trial-phase-2026-10'));
        self::assertSame(BookingOutcome::NoCharge, $receipt->outcome);
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame('rated', $this->value('SELECT state FROM billium_schedule_billing_occurrence'));
        self::assertSame('trial-phase-2026-10', $this->value('SELECT no_charge_reason FROM billium_rating_rated_basis'));
    }

    public function test_unknown_versioned_strategy_holds_without_financial_rows(): void
    {
        $this->application()->instance(PriceVersionStore::class, new readonly class($this->price()) implements PriceVersionStore
        {
            public function __construct(private PriceVersion $price) {}

            public function publish(PriceVersion $price, string $commandKey): array
            {
                throw new \LogicException('not used');
            }

            public function find(string $priceRef, int $version): PriceVersion
            {
                return FixtureArguments::construct(class: PriceVersion::class, arguments: [...get_object_vars($this->price), 'strategyVersion' => 99]);
            }
        });
        $held = $this->application()->make(RatingPreparer::class)->prepare($this->request());
        self::assertInstanceOf(RatingHold::class, $held);
        self::assertSame(HoldReason::UnknownPolicy, $held->reason);
        self::assertSame(0, array_sum($this->ratingCounts()));
    }
}
