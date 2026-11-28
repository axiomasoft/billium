<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PriceVersion as WirePriceVersion;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\QuantityRules;
use Billium\Rating\Data\Pricing\Tier;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Storage\RatingRows;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class WirePriceVersionMapper
{
    public const string SENTINEL_MAX_MINOR = '9223372036854775807';

    /**
     * @return array{price: PriceVersion, wireDigest: string, billableStream: array<string, mixed>}
     */
    public function map(
        WirePriceVersion $wire,
        string $priceRef,
        int $version,
        ?string $billingAccountRef = null,
        ?string $periodRef = null,
    ): array {
        $codec = new Codec;
        $redecoded = $codec->decode($codec->encode($wire));
        assert($redecoded instanceof WirePriceVersion);
        if ($codec->digestOf($redecoded) !== $redecoded->digest) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.wire_price_digest_mismatch');
        }
        $currency = Currency::of($redecoded->currency);
        $strategyRaw = $redecoded->strategy;
        $strategyId = StrategyId::from(RatingRows::text($strategyRaw->id ?? null));
        $strategyVersion = RatingRows::integer($strategyRaw->version ?? null);
        $terms = match ($strategyId) {
            StrategyId::Fixed => new FixedTerms(
                amount: RatingRows::text($strategyRaw->amount ?? null),
                scope: 'per_component_occurrence',
            ),
            StrategyId::PerUnit => new PerUnitTerms(unitPrice: RatingRows::text($strategyRaw->unitPrice ?? null)),
            StrategyId::TieredGraduated => new GraduatedTerms(tiers: $this->tiers(is_iterable($strategyRaw->tiers ?? null) ? $strategyRaw->tiers : [])),
            StrategyId::TieredVolume => new VolumeTerms(tiers: $this->tiers(is_iterable($strategyRaw->tiers ?? null) ? $strategyRaw->tiers : [])),
        };
        $conv = $strategyRaw->quantityConversion ?? null;
        $rules = $conv instanceof stdClass
            ? new QuantityRules(
                quantityScale: RatingRows::integer($conv->quantityScale ?? null),
                conversionNumerator: RatingRows::text($conv->numerator ?? null),
                conversionDenominator: RatingRows::text($conv->denominator ?? null),
                billingIncrement: '1',
                incrementMode: IncrementMode::None,
            )
            : new QuantityRules(
                quantityScale: 9,
                conversionNumerator: '1',
                conversionDenominator: '1',
                billingIncrement: '1',
                incrementMode: IncrementMode::None,
            );
        $roundingMode = match (RatingRows::text($redecoded->rounding->mode ?? null)) {
            'HALF_UP' => RoundingMode::HalfUp,
            'HALF_EVEN' => RoundingMode::HalfEven,
            default => throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.unsupported_rounding_mode'),
        };
        $minMinor = RatingRows::text($redecoded->commitment->minimumMinor ?? null);
        $maxMinor = RatingRows::text($redecoded->commitment->maximumMinor ?? null);
        $commitment = null;
        if ($minMinor !== '0' || $maxMinor !== self::SENTINEL_MAX_MINOR) {
            if ($billingAccountRef === null || $billingAccountRef === '' || $periodRef === null || $periodRef === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.commitment_context_required');
            }
            $commitment = new CommitmentTerms(
                sellerRef: $redecoded->sellerRef,
                billingAccountRef: $billingAccountRef,
                componentRef: $redecoded->componentRef,
                currency: $redecoded->currency,
                periodRef: $periodRef,
                minimumMinor: (int) $minMinor,
                maximumMinor: (int) $maxMinor,
                consumedByAlreadyBilled: true,
                appliesAt: 'period_final',
            );
        }
        $draft = new PriceVersion(
            sellerRef: $redecoded->sellerRef,
            priceRef: $priceRef,
            version: $version,
            strategyId: $strategyId,
            strategyVersion: $strategyVersion,
            currency: $currency->code,
            currencyExponent: $currency->scale,
            exponentVersion: $redecoded->currencyExponentVersion,
            unit: $redecoded->unit,
            quantityRules: $rules,
            terms: $terms,
            roundingMode: $roundingMode,
            roundingStage: RoundingStage::AfterTax,
            roundingPolicyVersion: RatingRows::text($redecoded->rounding->policy ?? null),
            commitment: $commitment,
            termsDigest: 'draft',
        );
        $price = new PriceVersion(
            sellerRef: $draft->sellerRef,
            priceRef: $draft->priceRef,
            version: $draft->version,
            strategyId: $draft->strategyId,
            strategyVersion: $draft->strategyVersion,
            currency: $draft->currency,
            currencyExponent: $draft->currencyExponent,
            exponentVersion: $draft->exponentVersion,
            unit: $draft->unit,
            quantityRules: $draft->quantityRules,
            terms: $draft->terms,
            roundingMode: $draft->roundingMode,
            roundingStage: $draft->roundingStage,
            roundingPolicyVersion: $draft->roundingPolicyVersion,
            commitment: $draft->commitment,
            termsDigest: CanonicalJson::termsDigest($draft),
        );
        /** @var array<string, mixed> $billableStream */
        $billableStream = json_decode($codec->canonicalize($redecoded->billableStream), true, 16, JSON_THROW_ON_ERROR);

        return [
            'price' => $price,
            'wireDigest' => $redecoded->digest,
            'billableStream' => $billableStream,
        ];
    }

    /**
     * @param  iterable<mixed>  $items
     * @return list<Tier>
     */
    private function tiers(iterable $items): array
    {
        $tiers = [];
        $index = 0;
        foreach ($items as $item) {
            if (! $item instanceof stdClass) {
                continue;
            }
            $tiers[] = new Tier(
                index: $index++,
                lower: '0',
                upper: isset($item->upTo) ? RatingRows::text($item->upTo) : null,
                unitPrice: RatingRows::text($item->unitPrice ?? null),
                flatFee: '0',
            );
        }

        return $tiers;
    }
}
