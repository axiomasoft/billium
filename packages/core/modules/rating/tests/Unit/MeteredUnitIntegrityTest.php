<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenPrice;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\QuoteRequest;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\FixedOccurrenceInput;
use Billium\Rating\Internal\Pipeline\InputsDigest;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Rating\Internal\Tax\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class MeteredUnitIntegrityTest extends TestCase
{
    private function computation(): RatingComputation
    {
        return new RatingComputation(strategies: new StrategyCatalog, taxes: new TaxCalculator);
    }

    /** @return array<string, mixed> */
    private function baseInputsArray(string $aggregateUnit = 'call', string $priceUnit = 'call'): array
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/pre-f1/graduated-original.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        $inputs = $fixture['given']['frozenInputs'];
        $inputs['aggregates'][0]['unit'] = $aggregateUnit;
        $inputs['aggregates'][0]['snapshot']['unit'] = $aggregateUnit;
        $inputs['prices'][0]['snapshot']['unit'] = $priceUnit;

        return $inputs;
    }

    private function buildInputs(string $aggregateUnit, string $priceUnit, ?string $snapshotUnit = null, bool $omitAggregateUnit = false): FrozenRatingInputs
    {
        $data = $this->baseInputsArray($aggregateUnit, $priceUnit);
        if ($omitAggregateUnit) {
            unset($data['aggregates'][0]['unit']);
        }
        if ($snapshotUnit !== null) {
            $data['aggregates'][0]['snapshot']['unit'] = $snapshotUnit;
        } elseif ($omitAggregateUnit) {
            unset($data['aggregates'][0]['snapshot']['unit']);
        }

        $decoded = FrozenInputsCodec::decode(CanonicalJson::encode($data));
        if (! $omitAggregateUnit && $aggregateUnit !== '') {
            // Ensure $aggregate->unit is populated even before FrozenInputsCodec is updated, or via FixtureArguments
            $agg = $decoded->aggregates[0];
            if (property_exists($agg, 'unit') && $agg->unit !== $aggregateUnit) {
                $agg = FixtureArguments::construct(class: FrozenAggregate::class, arguments: [
                    ...get_object_vars($agg),
                    'unit' => $aggregateUnit,
                ]);
                $decoded = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [
                    ...get_object_vars($decoded),
                    'aggregates' => [$agg],
                ]);
            }
        }

        return $decoded;
    }

    public function test_matching_call_and_second_units_compute_normally(): void
    {
        foreach (['call', 'second'] as $unit) {
            $inputs = $this->buildInputs($unit, $unit, $unit);
            $quote = $this->computation()->quote(new QuoteRequest($inputs));
            self::assertSame('10000', $quote->grossMinor);
        }
    }

    public function test_call_aggregate_with_byte_or_token_price_is_refused(): void
    {
        foreach (['byte', 'token'] as $priceUnit) {
            $inputs = $this->buildInputs('call', $priceUnit, 'call');
            try {
                $this->computation()->quote(new QuoteRequest($inputs));
                self::fail("Expected unit mismatch for call vs {$priceUnit}");
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
                self::assertStringContainsString('billium.rating.unit_mismatch', $e->detail);
            }
        }
    }

    public function test_metered_aggregate_without_verified_unit_is_refused(): void
    {
        $inputs = $this->buildInputs('', 'call', null, true);
        try {
            $this->computation()->quote(new QuoteRequest($inputs));
            self::fail('Expected unit_unverified for metered aggregate without unit');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertStringContainsString('billium.rating.unit_unverified', $e->detail);
        }
    }

    public function test_diverging_aggregate_and_snapshot_unit_representations_are_refused(): void
    {
        $inputs = $this->buildInputs('call', 'call', 'byte');
        try {
            $this->computation()->quote(new QuoteRequest($inputs));
            self::fail('Expected unit_representation_mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertStringContainsString('billium.rating.unit_representation_mismatch', $e->detail);
        }
    }

    public function test_pre_f1_inputs_digest_is_preserved_without_unit(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/pre-f1/graduated-original.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        $decoded = FrozenInputsCodec::decode($fixture['script'][0]['args']['frozen_inputs_json']);
        self::assertSame($fixture['expect'][0]['ok']['inputsDigest'], InputsDigest::compute($decoded));

        try {
            $this->computation()->quote(new QuoteRequest($decoded));
            self::fail('Expected pre-F1 graduated-original.json without aggregate unit to refuse');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertStringContainsString('billium.rating.unit_unverified', $e->detail);
        }
    }

    public function test_fixed_occurrence_passes_without_usage_unit(): void
    {
        $data = $this->baseInputsArray('call', 'seat');
        $priceArgs = get_object_vars(FrozenInputsCodec::decode(CanonicalJson::encode($data))->prices[0]->snapshot);
        $priceArgs['strategyId'] = StrategyId::Fixed;
        $priceArgs['terms'] = new FixedTerms(amount: '1490.00', scope: 'per_component_occurrence');
        $draft = FixtureArguments::construct(class: PriceVersion::class, arguments: [...$priceArgs, 'termsDigest' => 'draft']);
        $fixedPrice = FixtureArguments::construct(class: PriceVersion::class, arguments: [...$priceArgs, 'termsDigest' => CanonicalJson::termsDigest($draft)]);

        $inputs = FrozenInputsCodec::decode(CanonicalJson::encode($data));
        $agg = $inputs->aggregates[0];
        $group = json_decode($inputs->attribution, true, 32, JSON_THROW_ON_ERROR);
        $group['billingAccountRef'] = $inputs->billingAccountRef;
        $group['agreementRevision'] = $inputs->agreementRevision;
        $group['componentRef'] = $agg->componentRef;
        $fixedAgg = FixedOccurrenceInput::freeze(
            seller: $inputs->sellerRef,
            environment: $inputs->environment,
            occurrence: $inputs->occurrenceRef,
            sourceDigest: $inputs->sourceDigest,
            occurrenceDigest: $inputs->occurrenceInputsDigest,
            start: $inputs->periodStartMicros,
            end: $inputs->periodEndMicros,
            group: $group,
            price: $fixedPrice,
        );
        $oldFp = $inputs->prices[0];
        $fixedFp = new FrozenPrice(
            componentRef: $oldFp->componentRef,
            priceRef: $fixedPrice->priceRef,
            priceVersion: $fixedPrice->version,
            strategyId: $fixedPrice->strategyId->value,
            strategyVersion: $fixedPrice->strategyVersion,
            termsDigest: $fixedPrice->termsDigest,
            commitmentScopeDigest: null,
            commitmentBasisDigest: null,
            alreadyBilledNetMinor: null,
            roundingPolicyVersion: $fixedPrice->roundingPolicyVersion,
            exponentVersion: $fixedPrice->exponentVersion,
            snapshot: $fixedPrice,
        );
        $fixedInputs = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [
            ...get_object_vars($inputs),
            'aggregates' => [$fixedAgg],
            'prices' => [$fixedFp],
        ]);
        $quote = $this->computation()->quote(new QuoteRequest($fixedInputs));
        self::assertSame('149000', $quote->grossMinor);
    }
}
