<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\RatingQueries;
use Billium\Rating\Tests\RatingTestCase;

final class RatingPipelineTaxTest extends RatingTestCase
{
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
        self::assertSame($line->grossMinor, bcadd($line->netMinor, $line->taxMinor, 0));
        self::assertStringContainsString('0.03', $line->intermediatesJson);
        self::assertSame(1, $this->value('SELECT residual_minor FROM billium_rating_rated_line'));
    }
}
