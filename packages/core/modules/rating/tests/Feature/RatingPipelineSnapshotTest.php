<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingQueries;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\PreparedRating;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Tests\RatingTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class RatingPipelineSnapshotTest extends RatingTestCase
{
    public function test_frozen_lines_replay_without_current_prices_or_tax_and_cannot_be_mutated(): void
    {
        $this->tax->rate = '0.2';
        $prepared = $this->prepared();
        self::assertSame('10000', $prepared->draft->netMinor);
        self::assertSame('2000', $prepared->draft->taxMinor);
        self::assertSame('12000', $prepared->draft->grossMinor);
        $receipt = $this->book($prepared);
        self::assertSame(BookingOutcome::Booked, $receipt->outcome);
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '999'), commandKey: 'price-v2');
        $this->tax->rate = '0.9';
        $this->tax->missing = true;
        $queries = $this->application()->make(RatingQueries::class);
        self::assertSame(CanonicalJson::encode($prepared->draft), CanonicalJson::encode($queries->replay($receipt->basisRef)));
        self::assertSame(1, $this->tax->calls);
        $basis = $queries->basis($receipt->basisRef);
        self::assertNotNull($basis);
        $line = $basis->lines[0];
        self::assertSame('api_calls/1', $line->metricVersion);
        self::assertSame(1, $line->priceVersion);
        self::assertSame('0.2', $line->taxRate);
        self::assertSame($prepared->inputs->aggregates[0]->snapshotDigest, $line->aggregateSnapshotDigest);
        self::assertSame($prepared->inputs->periodStartMicros, $line->windowStartMicros);
        self::assertSame('test-rounding/1', $line->roundingPolicyVersion);
        self::assertNull($line->predecessorBasisRef);
        foreach (['run', 'rated_basis', 'rated_line', 'booking', 'event'] as $table) {
            self::assertSame('55000', self::sqlState($this->owner()->getPdo(), 'DELETE FROM billium_rating_'.$table));
        }
    }

    public function test_retries_reuse_one_basis_one_obligation_and_conflict_on_changed_semantics(): void
    {
        $prepared = $this->prepared();
        $first = $this->book($prepared);
        self::assertSame($first->body(), $this->book($prepared)->body());
        $existing = $this->book(prepared: $prepared, key: 'book-2');
        self::assertSame(BookingOutcome::Existing, $existing->outcome);
        self::assertSame($first->obligationRef, $existing->obligationRef);
        self::assertSame(1, $this->rows('billium_rating_run'));
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(1, $this->rows('billium_rating_booking'));
        $in = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($prepared->inputs), 'sourceDigest' => 'different']);
        $draft = $this->application()->make(RatingComputation::class)->compute($in);
        try {
            $this->book(new PreparedRating(inputs: $in, draft: $draft));
            self::fail('same key with changed inputs accepted');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }
    }
}
