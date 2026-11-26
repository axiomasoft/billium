<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Storage;

use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenPrice;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\FrozenTax;
use Billium\Rating\Data\Rating\RatedLineDraft;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Enums\TaxMode;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use DateTimeImmutable;
use Packman\Attributes\Internal;

/** Retained v1 snapshots: explicit decoding, no current registry or vendor reads (D56). */
#[Internal]
final class FrozenInputsCodec
{
    public static function decode(string $json): FrozenRatingInputs
    {
        $data = RatingRows::map(json_decode($json, true, 128, JSON_THROW_ON_ERROR));
        // Validate nested metadata as well as amounts: JSON floats are never financial inputs.
        CanonicalJson::encode($data);

        return self::inputs($data);
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : RatingRows::text($value);
    }

    /** @param array<string, mixed> $d */
    private static function terms(array $d): PriceTerms
    {
        $terms = RatingRows::map($d['terms'] ?? null);
        $id = StrategyId::from(RatingRows::text($d['strategyId'] ?? null));
        if ($id === StrategyId::Fixed) {
            return new FixedTerms(amount: RatingRows::text($terms['amount'] ?? null), scope: RatingRows::text($terms['scope'] ?? null));
        }
        if ($id === StrategyId::PerUnit) {
            return new PerUnitTerms(unitPrice: RatingRows::text($terms['unitPrice'] ?? null));
        }
        $tiers = array_map(static function (mixed $item): Tier {
            $t = RatingRows::map($item);

            return new Tier(index: RatingRows::integer($t['index'] ?? null), lower: RatingRows::text($t['lower'] ?? null),
                upper: self::nullableText($t['upper'] ?? null), unitPrice: RatingRows::text($t['unitPrice'] ?? null), flatFee: RatingRows::text($t['flatFee'] ?? null));
        }, RatingRows::items($terms['tiers'] ?? null));

        return $id === StrategyId::TieredGraduated ? new GraduatedTerms(tiers: $tiers) : new VolumeTerms(tiers: $tiers);
    }

    /** @param array<string, mixed> $d */
    private static function inputs(array $d): FrozenRatingInputs
    {
        return new FrozenRatingInputs(
            sellerRef: RatingRows::text($d['sellerRef'] ?? null),
            environment: RatingRows::text($d['environment'] ?? null),
            billingAccountRef: RatingRows::text($d['billingAccountRef'] ?? null),
            occurrenceRef: RatingRows::text($d['occurrenceRef'] ?? null),
            periodStartMicros: RatingRows::integer($d['periodStartMicros'] ?? null),
            periodEndMicros: RatingRows::integer($d['periodEndMicros'] ?? null),
            attribution: RatingRows::text($d['attribution'] ?? null),
            agreementRevision: RatingRows::text($d['agreementRevision'] ?? null),
            currency: RatingRows::text($d['currency'] ?? null),
            currencyExponent: RatingRows::integer($d['currencyExponent'] ?? null),
            aggregates: array_map(static fn (mixed $item): FrozenAggregate => self::aggregate(RatingRows::map($item)), RatingRows::items($d['aggregates'] ?? null)),
            prices: array_map(static fn (mixed $item): FrozenPrice => self::frozenPrice(RatingRows::map($item)), RatingRows::items($d['prices'] ?? null)),
            taxes: array_map(static fn (mixed $item): FrozenTax => self::frozenTax(RatingRows::map($item)), RatingRows::items($d['taxes'] ?? null)),
            occurrenceInputsDigest: RatingRows::text($d['occurrenceInputsDigest'] ?? null),
            sourceDigest: RatingRows::text($d['sourceDigest'] ?? null),
            frontierExpectedDigest: RatingRows::text($d['frontierExpectedDigest'] ?? null),
            frontierExpectedRowVersion: RatingRows::integer($d['frontierExpectedRowVersion'] ?? null),
            noChargeReason: self::nullableText($d['noChargeReason'] ?? null),
            proration: isset($d['proration']) ? self::proration(RatingRows::map($d['proration'])) : null,
            correctionEvidence: isset($d['correctionEvidence']) ? RatingRows::map($d['correctionEvidence']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{elapsed: string, total: string, amendmentRef: string, revision: int, effectiveAt: string, policy: string, timezone: ?string}
     */
    private static function proration(array $data): array
    {
        return ['elapsed' => RatingRows::text($data['elapsed'] ?? null), 'total' => RatingRows::text($data['total'] ?? null),
            'amendmentRef' => RatingRows::text($data['amendmentRef'] ?? null), 'revision' => RatingRows::integer($data['revision'] ?? null),
            'effectiveAt' => RatingRows::text($data['effectiveAt'] ?? null), 'policy' => RatingRows::text($data['policy'] ?? null),
            'timezone' => self::nullableText($data['timezone'] ?? null)];
    }

    /** @param array<string, mixed> $d */
    private static function aggregate(array $d): FrozenAggregate
    {
        return new FrozenAggregate(
            componentRef: RatingRows::text($d['componentRef'] ?? null),
            metricRef: RatingRows::text($d['metricRef'] ?? null),
            metricVersion: is_int($d['metricVersion'] ?? null) ? $d['metricVersion'] : RatingRows::text($d['metricVersion'] ?? null),
            snapshotRef: RatingRows::text($d['snapshotRef'] ?? null),
            snapshotDigest: RatingRows::text($d['snapshotDigest'] ?? null),
            windowStartMicros: RatingRows::integer($d['windowStartMicros'] ?? null),
            windowEndMicros: RatingRows::integer($d['windowEndMicros'] ?? null),
            completenessFrontierDigest: RatingRows::text($d['completenessFrontierDigest'] ?? null),
            quantity: RatingRows::text($d['quantity'] ?? null),
            quantityScale: RatingRows::integer($d['quantityScale'] ?? null),
            snapshot: RatingRows::map($d['snapshot'] ?? null),
            quantitySnapshot: isset($d['quantitySnapshot']) ? RatingRows::map($d['quantitySnapshot']) : null,
        );
    }

    /** @param array<string, mixed> $d */
    private static function frozenPrice(array $d): FrozenPrice
    {
        return new FrozenPrice(
            componentRef: RatingRows::text($d['componentRef'] ?? null),
            priceRef: RatingRows::text($d['priceRef'] ?? null),
            priceVersion: RatingRows::integer($d['priceVersion'] ?? null),
            strategyId: RatingRows::text($d['strategyId'] ?? null),
            strategyVersion: RatingRows::integer($d['strategyVersion'] ?? null),
            termsDigest: RatingRows::text($d['termsDigest'] ?? null),
            commitmentScopeDigest: self::nullableText($d['commitmentScopeDigest'] ?? null),
            commitmentBasisDigest: self::nullableText($d['commitmentBasisDigest'] ?? null),
            alreadyBilledNetMinor: self::nullableText($d['alreadyBilledNetMinor'] ?? null),
            roundingPolicyVersion: RatingRows::text($d['roundingPolicyVersion'] ?? null),
            exponentVersion: RatingRows::text($d['exponentVersion'] ?? null),
            snapshot: self::price(RatingRows::map($d['snapshot'] ?? null)),
        );
    }

    /** @param array<string, mixed> $d */
    private static function frozenTax(array $d): FrozenTax
    {
        return new FrozenTax(
            snapshotRef: RatingRows::text($d['snapshotRef'] ?? null),
            snapshotDigest: RatingRows::text($d['snapshotDigest'] ?? null),
            inputDigest: RatingRows::text($d['inputDigest'] ?? null),
            jurisdiction: RatingRows::text($d['jurisdiction'] ?? null),
            taxCode: RatingRows::text($d['taxCode'] ?? null),
            mode: RatingRows::text($d['mode'] ?? null),
            rate: RatingRows::text($d['rate'] ?? null),
            snapshot: self::tax(RatingRows::map($d['snapshot'] ?? null)),
        );
    }

    /** @param array<string, mixed> $d */
    public static function line(array $d): RatedLineDraft
    {
        return new RatedLineDraft(
            lineNo: RatingRows::integer($d['lineNo'] ?? null),
            componentRef: RatingRows::text($d['componentRef'] ?? null),
            metricRef: RatingRows::text($d['metricRef'] ?? null),
            metricVersion: is_int($d['metricVersion'] ?? null) ? $d['metricVersion'] : RatingRows::text($d['metricVersion'] ?? null),
            priceRef: RatingRows::text($d['priceRef'] ?? null),
            priceVersion: RatingRows::integer($d['priceVersion'] ?? null),
            strategyId: RatingRows::text($d['strategyId'] ?? null),
            strategyVersion: RatingRows::integer($d['strategyVersion'] ?? null),
            termsDigest: RatingRows::text($d['termsDigest'] ?? null),
            aggregateSnapshotDigest: RatingRows::text($d['aggregateSnapshotDigest'] ?? null),
            windowStartMicros: RatingRows::integer($d['windowStartMicros'] ?? null),
            windowEndMicros: RatingRows::integer($d['windowEndMicros'] ?? null),
            quantity: RatingRows::text($d['quantity'] ?? null),
            quantityScale: RatingRows::integer($d['quantityScale'] ?? null),
            rawAmount: RatingRows::text($d['rawAmount'] ?? null),
            commitmentAdjustment: RatingRows::text($d['commitmentAdjustment'] ?? null),
            pricedAmount: RatingRows::text($d['pricedAmount'] ?? null),
            roundingStage: RatingRows::text($d['roundingStage'] ?? null),
            roundingMode: RatingRows::text($d['roundingMode'] ?? null),
            roundingPolicyVersion: RatingRows::text($d['roundingPolicyVersion'] ?? null),
            exponentVersion: RatingRows::text($d['exponentVersion'] ?? null),
            taxSnapshotRef: RatingRows::text($d['taxSnapshotRef'] ?? null),
            jurisdiction: RatingRows::text($d['jurisdiction'] ?? null),
            taxCode: RatingRows::text($d['taxCode'] ?? null),
            taxMode: RatingRows::text($d['taxMode'] ?? null),
            taxRate: RatingRows::text($d['taxRate'] ?? null),
            netMinor: RatingRows::text($d['netMinor'] ?? null),
            taxMinor: RatingRows::text($d['taxMinor'] ?? null),
            grossMinor: RatingRows::text($d['grossMinor'] ?? null),
            residualMinor: RatingRows::text($d['residualMinor'] ?? null),
            intermediatesJson: RatingRows::text($d['intermediatesJson'] ?? null),
            predecessorBasisRef: self::nullableText($d['predecessorBasisRef'] ?? null),
            commitmentScopeDigest: self::nullableText($d['commitmentScopeDigest'] ?? null),
        );
    }

    /** @param array<string, mixed> $d */
    public static function price(array $d): PriceVersion
    {
        return new PriceVersion(
            sellerRef: RatingRows::text($d['sellerRef'] ?? null),
            priceRef: RatingRows::text($d['priceRef'] ?? null),
            version: RatingRows::integer($d['version'] ?? null),
            strategyId: StrategyId::from(RatingRows::text($d['strategyId'] ?? null)),
            strategyVersion: RatingRows::integer($d['strategyVersion'] ?? null),
            currency: RatingRows::text($d['currency'] ?? null),
            currencyExponent: RatingRows::integer($d['currencyExponent'] ?? null),
            exponentVersion: RatingRows::text($d['exponentVersion'] ?? null),
            unit: RatingRows::text($d['unit'] ?? null),
            quantityRules: self::quantity(RatingRows::map($d['quantityRules'] ?? null)),
            terms: self::terms($d),
            roundingMode: RoundingMode::from(RatingRows::text($d['roundingMode'] ?? null)),
            roundingStage: RoundingStage::from(RatingRows::text($d['roundingStage'] ?? null)),
            roundingPolicyVersion: RatingRows::text($d['roundingPolicyVersion'] ?? null),
            commitment: ($d['commitment'] ?? null) === null ? null : self::commitment(RatingRows::map($d['commitment'])),
            termsDigest: RatingRows::text($d['termsDigest'] ?? null),
        );
    }

    /** @param array<string, mixed> $d */
    private static function quantity(array $d): QuantityRules
    {
        return new QuantityRules(
            quantityScale: RatingRows::integer($d['quantityScale'] ?? null),
            conversionNumerator: RatingRows::text($d['conversionNumerator'] ?? null),
            conversionDenominator: RatingRows::text($d['conversionDenominator'] ?? null),
            billingIncrement: RatingRows::text($d['billingIncrement'] ?? null),
            incrementMode: IncrementMode::from(RatingRows::text($d['incrementMode'] ?? null)),
        );
    }

    /** @param array<string, mixed> $d */
    private static function commitment(array $d): CommitmentTerms
    {
        return new CommitmentTerms(
            sellerRef: RatingRows::text($d['sellerRef'] ?? null),
            billingAccountRef: RatingRows::text($d['billingAccountRef'] ?? null),
            componentRef: RatingRows::text($d['componentRef'] ?? null),
            currency: RatingRows::text($d['currency'] ?? null),
            periodRef: RatingRows::text($d['periodRef'] ?? null),
            minimumMinor: RatingRows::nullableInteger($d['minimumMinor'] ?? null),
            maximumMinor: RatingRows::nullableInteger($d['maximumMinor'] ?? null),
            consumedByAlreadyBilled: RatingRows::boolean($d['consumedByAlreadyBilled'] ?? null),
            appliesAt: RatingRows::text($d['appliesAt'] ?? null),
        );
    }

    /** @param array<string, mixed> $d */
    private static function tax(array $d): TaxSnapshot
    {
        return new TaxSnapshot(
            snapshotRef: RatingRows::text($d['snapshotRef'] ?? null),
            kind: TaxSnapshotKind::from(RatingRows::text($d['kind'] ?? null)),
            mode: TaxMode::from(RatingRows::text($d['mode'] ?? null)),
            jurisdiction: RatingRows::text($d['jurisdiction'] ?? null),
            taxCode: RatingRows::text($d['taxCode'] ?? null),
            rate: RatingRows::text($d['rate'] ?? null),
            effectiveDate: new DateTimeImmutable(RatingRows::text($d['effectiveDate'] ?? null)),
            taxPoint: new DateTimeImmutable(RatingRows::text($d['taxPoint'] ?? null)),
            noTaxReason: self::nullableText($d['noTaxReason'] ?? null),
            resolver: RatingRows::text($d['resolver'] ?? null),
            resolverVersion: RatingRows::text($d['resolverVersion'] ?? null),
            inputDigest: RatingRows::text($d['inputDigest'] ?? null),
            evidenceDigest: RatingRows::text($d['evidenceDigest'] ?? null),
        );
    }
}
