<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Tests\RatingTestCase;
use Illuminate\Database\QueryException;

final class RatingPipelineValidationTest extends RatingTestCase
{
    public function test_large_valid_fixed_price_can_be_prepared_and_booked(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(
            price: $this->price(version: 2, fixed: '100000000000000.00'), commandKey: 'large-price');
        $request = $this->request(priceVersion: 2);
        $components = $request->components;
        $components[0]['snapshotRef'] = 'fixed';
        $prepared = $this->application()->make(RatingPreparer::class)->prepare(FixtureArguments::construct(
            class: PrepareRating::class, arguments: [...get_object_vars($request), 'components' => $components]));
        self::assertInstanceOf(PreparedRating::class, $prepared);
        self::assertSame('10000000000000000', $prepared->draft->grossMinor);
        self::assertNotNull($this->book($prepared)->obligationRef);
        self::assertSame(10000000000000000, $this->value('SELECT gross_minor FROM billium_rating_rated_basis'));
        foreach (['raw_amount', 'commitment_adjustment', 'priced_amount'] as $column) {
            self::assertSame(64, $this->value('SELECT numeric_precision FROM information_schema.columns WHERE table_name=? AND column_name=?', ['billium_rating_rated_line', $column]));
            self::assertSame(24, $this->value('SELECT numeric_scale FROM information_schema.columns WHERE table_name=? AND column_name=?', ['billium_rating_rated_line', $column]));
        }
    }

    public function test_changed_total_is_rejected_even_when_the_client_keeps_the_original_digest(): void
    {
        $prepared = $this->prepared();
        $draft = FixtureArguments::construct(class: RatedBasisDraft::class, arguments: [...get_object_vars($prepared->draft), 'grossMinor' => '1']);
        try {
            $this->book(new PreparedRating(inputs: $prepared->inputs, draft: $draft));
            self::fail('tampered total accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.digest_mismatch', $error->detail);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
    }

    public function test_forged_aggregate_quantity_cannot_reuse_an_authentic_snapshot_reference(): void
    {
        $prepared = $this->prepared();
        $aggregate = FixtureArguments::construct(class: FrozenAggregate::class, arguments: [...get_object_vars($prepared->inputs->aggregates[0]), 'quantity' => '1000']);
        $in = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($prepared->inputs), 'aggregates' => [$aggregate]]);
        $draft = $this->application()->make(RatingComputation::class)->compute($in);
        try {
            $this->book(new PreparedRating(inputs: $in, draft: $draft));
            self::fail('forged quantity accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.usage.snapshot_superseded', $error->detail);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
    }

    public function test_database_constraints_refuse_wrong_totals_cross_seller_booking_and_frontier_rewrite(): void
    {
        config(['billium.modules.enabled' => ['platform', 'rating', 'usage', 'schedule']]);
        $this->book($this->prepared());
        $row = $this->owner()->table('billium_rating_rated_basis')->first();
        self::assertNotNull($row);
        $basis = get_object_vars($row);
        $invalid = $basis;
        unset($invalid['id']);
        $invalid['basis_ref'] = 'rb_'.str_repeat('b', 48);
        $invalid['occurrence_ref'] = 'invalid-totals';
        $invalid['gross_minor'] = self::integer($invalid['gross_minor']) + 1;
        try {
            $this->owner()->table('billium_rating_rated_basis')->insert($invalid);
            self::fail('invalid money equation accepted');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->getCode());
        }
        try {
            $this->owner()->table('billium_rating_booking')->insert(['seller_ref' => 'other', 'environment' => 'test', 'basis_id' => $basis['id'], 'outcome' => 'booked', 'obligation_ref' => 'wrong', 'obligation_basis_ref' => 'wrong']);
            self::fail('cross-seller booking accepted');
        } catch (QueryException $error) {
            self::assertSame('23503', $error->getCode());
        }
        self::assertSame('55000', self::sqlState($this->owner()->getPdo(), "UPDATE billium_rating_source_frontier SET head_digest='changed'"));
        self::assertSame(0, $this->rows('billium_rating_booking'));
    }
}
