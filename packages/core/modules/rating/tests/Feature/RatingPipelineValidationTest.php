<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Tests\RatingTestCase;
use Illuminate\Database\QueryException;

final class RatingPipelineValidationTest extends RatingTestCase
{
    public function test_changed_total_is_rejected_even_when_the_client_keeps_the_original_digest(): void
    {
        $prepared = $this->prepared();
        $draft = new RatedBasisDraft(...[...get_object_vars($prepared->draft), 'grossMinor' => '1']);
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
        $aggregate = new FrozenAggregate(...[...get_object_vars($prepared->inputs->aggregates[0]), 'quantity' => '1000']);
        $in = new FrozenRatingInputs(...[...get_object_vars($prepared->inputs), 'aggregates' => [$aggregate]]);
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
        $basis = get_object_vars($this->owner()->table('billium_rating_rated_basis')->first());
        $invalid = $basis;
        unset($invalid['id']);
        $invalid['basis_ref'] = 'rb_'.str_repeat('b', 48);
        $invalid['occurrence_ref'] = 'invalid-totals';
        $invalid['gross_minor']++;
        try {
            $this->owner()->table('billium_rating_rated_basis')->insert($invalid);
            self::fail('invalid money equation accepted');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->getCode());
        }
        try {
            $this->owner()->table('billium_rating_booking')->insert(['seller_ref' => 'other', 'environment' => 'test',
                'basis_id' => $basis['id'], 'outcome' => 'booked', 'obligation_ref' => 'wrong', 'obligation_basis_ref' => 'wrong']);
            self::fail('cross-seller booking accepted');
        } catch (QueryException $error) {
            self::assertSame('23503', $error->getCode());
        }
        self::assertSame('55000', self::sqlState($this->owner()->getPdo(), "UPDATE billium_rating_source_frontier SET head_digest='changed'"));
        self::assertSame(0, $this->rows('billium_rating_booking'));
    }
}
