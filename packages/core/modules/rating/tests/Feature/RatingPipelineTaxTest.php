<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\PrepareObligation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\PinnedRatingInputs;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingQueries;
use Billium\Rating\Tests\RatingTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class RatingPipelineTaxTest extends RatingTestCase
{
    public function test_wire_booking_uses_pinned_inputs_once_and_rejects_a_changed_snapshot(): void
    {
        $prepared = $this->prepared();
        $resolver = $this->createMock(PinnedRatingInputs::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($prepared);
        $codec = new Codec;
        $raw = ['schema' => PrepareObligation::SCHEMA, 'commandKey' => '98e68552-8fdb-4edb-8434-3b3bad0b56db',
            'installationRef' => self::INSTALLATION, 'environment' => $prepared->inputs->environment, 'sellerRef' => $prepared->inputs->sellerRef,
            'billingOccurrenceRef' => $prepared->inputs->occurrenceRef, 'agreementRef' => 'agreement-a', 'agreementRevision' => 1,
            'componentRef' => $prepared->inputs->prices[0]->componentRef, 'priceVersionRef' => 'price-a-1', 'snapshotDigest' => $prepared->inputs->digest()];
        $command = $codec->decode($codec->canonicalize($raw));
        self::assertInstanceOf(PrepareObligation::class, $command);
        $booking = $this->application()->make(RatingBooking::class);
        $original = $booking->prepareObligation($command, $resolver);
        self::assertSame($codec->encode($original), $codec->encode($booking->prepareObligation($command, $resolver)));
        $changed = $codec->decode($codec->canonicalize(array_replace($raw, ['snapshotDigest' => 'sha256:'.str_repeat('a', 64)])));
        self::assertInstanceOf(PrepareObligation::class, $changed);
        try {
            $booking->prepareObligation($changed, $resolver);
            self::fail('A changed snapshot replayed the original booking');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::IdempotencyConflict, $problem->problem);
        }
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_tax_residual_and_exact_intermediates_survive_booking_and_replay(): void
    {
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '0.03'), commandKey: 'fractional-price');
        $this->tax->rate = '0.2';
        $prepared = $this->prepared(priceVersion: 2);
        self::assertSame(['3', '1', '4'], [$prepared->draft->netMinor, $prepared->draft->taxMinor, $prepared->draft->grossMinor]);
        self::assertSame('1', $prepared->draft->lines[0]->residualMinor);
        $receipt = $this->book($prepared);
        $this->tax->rate = '0.8';
        $replay = $this->application()->make(RatingQueries::class)->replay($receipt->basisRef);
        self::assertSame($prepared->draft->ratedDigest, $replay->ratedDigest);
        $line = $replay->lines[0];
        self::assertTrue(is_numeric($line->netMinor));
        self::assertTrue(is_numeric($line->taxMinor));
        self::assertSame($line->grossMinor, bcadd($line->netMinor, $line->taxMinor, 0));
        self::assertStringContainsString('0.03', $line->intermediatesJson);
        self::assertSame(1, $this->value('SELECT residual_minor FROM billium_rating_rated_line'));
    }
}
