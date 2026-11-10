<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\RatingConformance;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Rating\Internal\Tax\TaxCalculator;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type PipelineFixture array{id: string, description: string, spec_refs: list<string>, profiles: list<string>, given: array{modules: list<string>, frozenInputs: array{taxes: list<array{mode: string, rate: string, taxCode: string, snapshot: array{kind: string, mode: string, rate: string, taxCode: string, resolver: string, taxPoint: string, inputDigest: string, noTaxReason: string, snapshotRef: string, jurisdiction: string, effectiveDate: string, evidenceDigest: string, resolverVersion: string}, inputDigest: string, snapshotRef: string, jurisdiction: string, snapshotDigest: string}>, prices: list<array{priceRef: string, snapshot: array{unit: string, terms: array{tiers: list<array{index: int, lower: string, upper: string|null, flatFee: string, unitPrice: string}>}, version: int, currency: string, priceRef: string, sellerRef: string, commitment: null, strategyId: string, termsDigest: string, roundingMode: string, quantityRules: array{incrementMode: string, quantityScale: int, billingIncrement: string, conversionNumerator: string, conversionDenominator: string}, roundingStage: string, exponentVersion: string, strategyVersion: int, currencyExponent: int, roundingPolicyVersion: string}, strategyId: string, termsDigest: string, componentRef: string, priceVersion: int, exponentVersion: string, strategyVersion: int, alreadyBilledNetMinor: null, commitmentBasisDigest: null, commitmentScopeDigest: null, roundingPolicyVersion: string}>, currency: string, sellerRef: string, aggregates: list<array{quantity: string, snapshot: array{window: array{group: array{region: string, periodRef: string, mappingRef: string, subjectRef: string, customerRef: string, agreementRef: string, componentRef: string, agreementRevision: string, billingAccountRef: string, attributionVersion: string}, endMicros: int, metricRef: string, sellerRef: string, environment: string, startMicros: int, metricVersion: string}, quantity: string, algorithmId: string, snapshotRef: string, distinctCount: null, quantityScale: int, snapshotDigest: string, memberSetDigest: null, reproducibility: string, algorithmVersion: int, snapshotRevision: int, predecessorDigest: null, reductionEvidence: array<string, mixed>, effectiveRevisions: list<array{factId: string, factDigest: string, factRevision: int}>, completenessFrontier: list<array{status: string, sourceRef: string, evidenceSeq: int, evidenceDigest: string, rangeEndMicros: int, watermarkMicros: int, rangeStartMicros: int, repairOrReplayRef: null}>, completenessFrontierDigest: string}, metricRef: string, snapshotRef: string, componentRef: string, metricVersion: string, quantityScale: int, snapshotDigest: string, windowEndMicros: int, windowStartMicros: int, completenessFrontierDigest: string}>, attribution: string, environment: string, sourceDigest: string, occurrenceRef: string, noChargeReason: null, periodEndMicros: int, currencyExponent: int, agreementRevision: string, billingAccountRef: string, periodStartMicros: int, frontierExpectedDigest: string, occurrenceInputsDigest: string, frontierExpectedRowVersion: int}}, script: list<array{op: string, args: array{action: string, frozen_inputs_json: string}}>, expect: list<array{ok: array{financial: bool, inputsDigest: string, netMinor: string, taxMinor: string, grossMinor: string, quoteDigest: string}}>}
 * @phpstan-type InputFixture array{taxes: list<array{mode: string, rate: string, taxCode: string, snapshot: array{kind: string, mode: string, rate: string, taxCode: string, resolver: string, taxPoint: string, inputDigest: string, noTaxReason: string, snapshotRef: string, jurisdiction: string, effectiveDate: string, evidenceDigest: string, resolverVersion: string}, inputDigest: string, snapshotRef: string, jurisdiction: string, snapshotDigest: string}>, prices: list<array{priceRef: string, snapshot: array{unit: string, terms: array{tiers: list<array{index: int, lower: string, upper: string|null, flatFee: string, unitPrice: string}>}, version: int, currency: string, priceRef: string, sellerRef: string, commitment: null, strategyId: string, termsDigest: string, roundingMode: string, quantityRules: array{incrementMode: string, quantityScale: int, billingIncrement: string, conversionNumerator: string, conversionDenominator: string}, roundingStage: string, exponentVersion: string, strategyVersion: int, currencyExponent: int, roundingPolicyVersion: string}, strategyId: string, termsDigest: string, componentRef: string, priceVersion: int, exponentVersion: string, strategyVersion: int, alreadyBilledNetMinor: null, commitmentBasisDigest: null, commitmentScopeDigest: null, roundingPolicyVersion: string}>, currency: string, sellerRef: string, aggregates: list<array{quantity: string, snapshot: array{window: array{group: array{region: string, periodRef: string, mappingRef: string, subjectRef: string, customerRef: string, agreementRef: string, componentRef: string, agreementRevision: string, billingAccountRef: string, attributionVersion: string}, endMicros: int, metricRef: string, sellerRef: string, environment: string, startMicros: int, metricVersion: string}, quantity: string, algorithmId: string, snapshotRef: string, distinctCount: null, quantityScale: int, snapshotDigest: string, memberSetDigest: null, reproducibility: string, algorithmVersion: int, snapshotRevision: int, predecessorDigest: null, reductionEvidence: array<string, mixed>, effectiveRevisions: list<array{factId: string, factDigest: string, factRevision: int}>, completenessFrontier: list<array{status: string, sourceRef: string, evidenceSeq: int, evidenceDigest: string, rangeEndMicros: int, watermarkMicros: int, rangeStartMicros: int, repairOrReplayRef: null}>, completenessFrontierDigest: string}, metricRef: string, snapshotRef: string, componentRef: string, metricVersion: string, quantityScale: int, snapshotDigest: string, windowEndMicros: int, windowStartMicros: int, completenessFrontierDigest: string}>, attribution: string, environment: string, sourceDigest: string, occurrenceRef: string, noChargeReason: null, periodEndMicros: int, currencyExponent: int, agreementRevision: string, billingAccountRef: string, periodStartMicros: int, frontierExpectedDigest: string, occurrenceInputsDigest: string, frontierExpectedRowVersion: int}
 */
final class RatingPipelineDigestTest extends TestCase
{
    /** @return PipelineFixture */
    private function fixture(): array
    {
        /** @var PipelineFixture $fixture */
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/rating/pipeline/graduated-original.json'), true, 128, JSON_THROW_ON_ERROR);

        return $fixture;
    }

    public function test_golden_quote_and_retained_input_codec_are_deterministic(): void
    {
        $fixture = $this->fixture();
        $handler = new RatingConformance(new RatingComputation(strategies: new StrategyCatalog, taxes: new TaxCalculator));
        self::assertEquals($fixture['expect'][0]['ok'], $handler(operation: 'rating.rate', arguments: (object) $fixture['script'][0]['args']));
        /** @var PipelineFixture $volume */
        $volume = json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/rating/pipeline/volume-original.json'), true, 128, JSON_THROW_ON_ERROR);
        self::assertEquals($volume['expect'][0]['ok'], $handler(operation: 'rating.rate', arguments: (object) $volume['script'][0]['args']));
        $in = FrozenInputsCodec::decode($fixture['script'][0]['args']['frozen_inputs_json']);
        self::assertSame(CanonicalJson::encode($in), CanonicalJson::encode(FrozenInputsCodec::decode(CanonicalJson::encode($in))));
    }

    public function test_each_semantic_axis_changes_identity_but_transport_and_cas_do_not(): void
    {
        $fixture = $this->fixture();
        $in = FrozenInputsCodec::decode($fixture['script'][0]['args']['frozen_inputs_json']);
        foreach (['agreementRevision' => '2', 'attribution' => '{"customerRef":"other"}', 'sourceDigest' => 'new-source', 'periodEndMicros' => $in->periodEndMicros + 1] as $key => $value) {
            $changed = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($in), $key => $value]);
            self::assertNotSame($in->digest(), $changed->digest(), $key);
        }
        $changed = FixtureArguments::construct(class: FrozenRatingInputs::class, arguments: [...get_object_vars($in), 'frontierExpectedDigest' => 'changed', 'frontierExpectedRowVersion' => 99]);
        self::assertSame($in->digest(), $changed->digest());
        foreach (['aggregates', 'prices', 'taxes'] as $list) {
            /** @var InputFixture $data */
            $data = json_decode(CanonicalJson::encode($in), true, 128, JSON_THROW_ON_ERROR);
            $field = ['aggregates' => 'snapshotDigest', 'prices' => 'termsDigest', 'taxes' => 'inputDigest'][$list];
            $data[$list][0][$field] = 'changed';
            self::assertNotSame($in->digest(), FrozenInputsCodec::decode(CanonicalJson::encode($data))->digest(), $list);
        }
    }

    public function test_float_is_rejected_even_inside_snapshot_metadata(): void
    {
        $fixture = $this->fixture();
        $inputs = $fixture['given']['frozenInputs'];
        $inputs['aggregates'][0]['snapshot']['float'] = 0.1;
        $this->expectException(LogicException::class);
        FrozenInputsCodec::decode(json_encode($inputs, JSON_THROW_ON_ERROR));
    }

    public function test_zero_quantity_does_not_invent_an_approved_zero_price(): void
    {
        $data = $this->fixture()['given']['frozenInputs'];
        $data['aggregates'][0]['quantity'] = '0.000000000';
        $data['noChargeReason'] = 'caller-cannot-invent-zero-price';
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.rating.zero_price_not_declared');
        new RatingComputation(strategies: new StrategyCatalog, taxes: new TaxCalculator)->compute(FrozenInputsCodec::decode(CanonicalJson::encode($data)));
    }

    public function test_tax_allocation_sums_the_group_before_distributing_residual_cents(): void
    {
        $data = $this->fixture()['given']['frozenInputs'];
        $price = &$data['prices'][0];
        $price['strategyId'] = $price['snapshot']['strategyId'] = 'fixed';
        $price['snapshot']['terms'] = ['amount' => '0.03', 'scope' => 'per_component_occurrence'];
        $price['termsDigest'] = $price['snapshot']['termsDigest'] = CanonicalJson::termsDigest(FrozenInputsCodec::price($price['snapshot']));
        unset($price);
        $tax = &$data['taxes'][0];
        $tax['mode'] = $tax['snapshot']['mode'] = 'exclusive';
        $tax['rate'] = $tax['snapshot']['rate'] = '0.2';
        $tax['snapshot']['noTaxReason'] = null;
        $tax['snapshotDigest'] = CanonicalJson::digest(FrozenInputsCodec::decode(CanonicalJson::encode([...$data, 'taxes' => [[...$tax, 'snapshotDigest' => 'not-checked-by-decoder']]]))->taxes[0]->snapshot);
        unset($tax);
        $data['aggregates'][1] = $data['aggregates'][0];
        $data['aggregates'][1]['componentRef'] = 'component-2';
        $data['aggregates'][1]['snapshot']['window']['group']['componentRef'] = 'component-2';
        $data['prices'][1] = $data['prices'][0];
        $data['prices'][1]['componentRef'] = 'component-2';
        $data['taxes'][1] = $data['taxes'][0];
        $draft = new RatingComputation(strategies: new StrategyCatalog, taxes: new TaxCalculator)->compute(FrozenInputsCodec::decode(CanonicalJson::encode($data)));
        self::assertSame(['6', '1', '7'], [$draft->netMinor, $draft->taxMinor, $draft->grossMinor]);
        self::assertSame(['1', '0'], array_column($draft->lines, 'taxMinor'));
    }
}
