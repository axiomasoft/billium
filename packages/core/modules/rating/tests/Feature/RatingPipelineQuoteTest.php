<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Contracts\RatingQuoter;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Data\Rating\QuoteRequest;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Tests\RatingTestCase;

final class RatingPipelineQuoteTest extends RatingTestCase
{
    public function test_quote_is_pure_and_excludes_cas_fields_from_identity(): void
    {
        $prepared = $this->prepared();
        $before = $this->ratingCounts();
        $quote = $this->application()->make(RatingQuoter::class)->quote(new QuoteRequest($prepared->inputs));
        self::assertFalse($quote->financial);
        self::assertSame($before, $this->ratingCounts());
        self::assertSame($prepared->draft->inputsDigest, $quote->inputsDigest);
        $changed = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($prepared->inputs), 'frontierExpectedRowVersion' => 999, 'frontierExpectedDigest' => 'other']);
        self::assertSame($prepared->inputs->digest(), $changed->digest());
    }

    public function test_changed_frontier_refuses_a_stale_preparation_without_a_new_basis(): void
    {
        $prepared = $this->prepared();
        $changed = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($prepared->inputs), 'sourceDigest' => 'next-source']);
        $draft = $this->application()->make(RatingComputation::class)->compute($changed);
        $this->book($prepared);
        try {
            $this->book(prepared: new PreparedRating(inputs: $changed, draft: $draft), key: 'stale');
            self::fail('stale frontier accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.frozen_input_stale', $error->detail);
        }
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key='stale'"));
    }
}
