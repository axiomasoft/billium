<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Pipeline;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Contracts\RatingQuoter;
use Billium\Rating\Data\Pricing\CommitmentBasis;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\PricingResult;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\Quote;
use Billium\Rating\Data\Rating\QuoteRequest;
use Billium\Rating\Data\Rating\RatedBasisDraft;
use Billium\Rating\Data\Rating\RatedLineDraft;
use Billium\Rating\Enums\ChargeKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Math\Dec;
use Billium\Rating\Internal\Money\MoneyFactory;
use Billium\Rating\Internal\Proration\ProrationCalculator;
use Billium\Rating\Internal\Proration\QuantitySnapshotScope;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Tax\TaxCalculator;

/** Versioned strategies consume retained snapshots only; this class performs no I/O (D56). */
final readonly class RatingComputation implements RatingQuoter
{
    public function __construct(private PricingStrategyRegistry $strategies, private TaxCalculator $taxes) {}

    public function quote(QuoteRequest $request): Quote
    {
        $draft = $this->compute($request->inputs);

        return new Quote(financial: false, inputsDigest: $draft->inputsDigest, netMinor: $draft->netMinor,
            taxMinor: $draft->taxMinor, grossMinor: $draft->grossMinor, quoteDigest: $draft->ratedDigest);
    }

    /** @param array{elapsed: string, total: string}|null $proration */
    public function compute(FrozenRatingInputs $inputs, ?array $proration = null, ?string $predecessorBasisRef = null, int $correctionRevision = 0): RatedBasisDraft
    {
        if ($inputs->periodStartMicros >= $inputs->periodEndMicros || $inputs->sellerRef === '' || $inputs->billingAccountRef === ''
            || $inputs->agreementRevision === '' || $inputs->sourceDigest === '' || $inputs->occurrenceInputsDigest === ''
            || ! in_array($inputs->environment, ['test', 'live'], true)
            || $inputs->aggregates === [] || count($inputs->aggregates) > 100
            || count($inputs->prices) !== count($inputs->aggregates) || count($inputs->taxes) !== count($inputs->aggregates)) {
            self::refuse('inputs_invalid');
        }
        $attribution = json_decode($inputs->attribution, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($attribution) || ! is_string($attribution['customerRef'] ?? null) || $attribution['customerRef'] === '') {
            self::refuse('attribution_missing');
        }
        $priced = $groups = $groupFor = [];
        $last = '';
        $zeroPrices = true;
        foreach ($inputs->aggregates as $i => $aggregate) {
            $window = RatingRows::map($aggregate->snapshot['window'] ?? null);
            $group = RatingRows::map($window['group'] ?? null);
            if (($group['billingAccountRef'] ?? null) !== $inputs->billingAccountRef || ($group['customerRef'] ?? null) !== $attribution['customerRef']
                || ($group['componentRef'] ?? null) !== $aggregate->componentRef || ($group['agreementRevision'] ?? null) !== $inputs->agreementRevision) {
                self::refuse('aggregate_scope_mismatch');
            }
            $price = $inputs->prices[$i];
            $tax = $inputs->taxes[$i];
            $snapshot = $price->snapshot;
            FixedOccurrenceInput::assertValid(inputs: $inputs, aggregate: $aggregate, price: $snapshot);
            QuantitySnapshotScope::assertValid(inputs: $inputs, aggregate: $aggregate, price: $snapshot);
            if (strcmp($aggregate->componentRef, $last) <= 0 || $aggregate->componentRef !== $price->componentRef
                || $aggregate->windowStartMicros !== $inputs->periodStartMicros || $aggregate->windowEndMicros !== $inputs->periodEndMicros
                || $snapshot->sellerRef !== $inputs->sellerRef || $snapshot->currency !== $inputs->currency
                || $snapshot->currencyExponent !== $inputs->currencyExponent
                || [$price->priceRef, $price->priceVersion, $price->strategyId, $price->strategyVersion, $price->termsDigest, $price->roundingPolicyVersion, $price->exponentVersion]
                    !== [$snapshot->priceRef, $snapshot->version, $snapshot->strategyId->value, $snapshot->strategyVersion, $snapshot->termsDigest, $snapshot->roundingPolicyVersion, $snapshot->exponentVersion]
                || CanonicalJson::digest($tax->snapshot) !== $tax->snapshotDigest
                || [$tax->snapshotRef, $tax->inputDigest, $tax->jurisdiction, $tax->taxCode, $tax->mode, $tax->rate]
                    !== [$tax->snapshot->snapshotRef, $tax->snapshot->inputDigest, $tax->snapshot->jurisdiction, $tax->snapshot->taxCode, $tax->snapshot->mode->value, $tax->snapshot->rate]) {
                self::refuse('inputs_invalid');
            }
            $last = $aggregate->componentRef;
            $commitment = $snapshot->commitment;
            $zeroPrices = $zeroPrices && self::declaresZero($snapshot);
            if ($commitment !== null && $tax->mode === 'inclusive') {
                self::refuse('unsupported_combination');
            }
            if ($commitment !== null && ($commitment->billingAccountRef !== $inputs->billingAccountRef || $commitment->componentRef !== $aggregate->componentRef
                || $price->commitmentScopeDigest !== self::commitmentScope($inputs->environment, $commitment)
                || $price->alreadyBilledNetMinor === null || $price->commitmentBasisDigest === null)) {
                self::refuse('commitment_basis_stale');
            }
            $this->strategies->validate($snapshot);
            $result = $this->strategies->resolve(id: $snapshot->strategyId, version: $snapshot->strategyVersion)->price(
                new PricingInput(price: $snapshot, quantity: $aggregate->quantity,
                    commitmentBasis: $commitment === null ? null : new CommitmentBasis(alreadyBilledNetMinor: $price->alreadyBilledNetMinor ?? '', basisDigest: $price->commitmentBasisDigest ?? '')));
            if ($proration !== null) {
                $minor = (new ProrationCalculator)->prorateMinor(fullMajor: Dec::parse($result->pricedAmount), exponent: $inputs->currencyExponent,
                    elapsed: $proration['elapsed'], total: $proration['total'], roundingMode: $snapshot->roundingMode->value);
                $result = new PricingResult(billableQuantity: $result->billableQuantity, rawAmount: $result->rawAmount,
                    commitmentAdjustment: $result->commitmentAdjustment, pricedAmount: Dec::ofUnscaled($minor, $inputs->currencyExponent)->toString(),
                    steps: $result->steps, resultDigest: CanonicalJson::digest([$result, $proration, $minor]));
            }
            $priced[$i] = $result;
            $group = CanonicalJson::digest([$tax->jurisdiction, $tax->taxCode, $tax->mode, $tax->rate, $tax->snapshot->noTaxReason,
                $snapshot->roundingMode, $snapshot->roundingPolicyVersion]);
            $key = 'line:'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $groups[$group][$key] = $result->pricedAmount;
            $groupFor[$i] = [$group, $key];
        }
        $allocated = [];
        foreach ($groupFor as $i => [$group, $key]) {
            if (! isset($allocated[$group])) {
                $price = $inputs->prices[$i]->snapshot;
                $allocated[$group] = $this->taxes->calculateSnapshot(amounts: $groups[$group], snapshot: $inputs->taxes[$i]->snapshot,
                    exp: $inputs->currencyExponent, rounding: $price->roundingMode->value, commitment: $price->commitment);
            }
        }
        $lines = [];
        $net = $taxTotal = $gross = '0';
        foreach ($inputs->aggregates as $i => $aggregate) {
            $price = $inputs->prices[$i];
            $tax = $inputs->taxes[$i];
            [$group, $key] = $groupFor[$i];
            $amounts = $allocated[$group][$key];
            $result = $priced[$i];
            $lines[] = new RatedLineDraft(lineNo: $i + 1, componentRef: $aggregate->componentRef, metricRef: $aggregate->metricRef,
                metricVersion: (string) $aggregate->metricVersion, priceRef: $price->priceRef, priceVersion: $price->priceVersion,
                strategyId: $price->strategyId, strategyVersion: $price->strategyVersion, termsDigest: $price->termsDigest,
                aggregateSnapshotDigest: $aggregate->snapshotDigest, windowStartMicros: $aggregate->windowStartMicros, windowEndMicros: $aggregate->windowEndMicros,
                quantity: $aggregate->quantity, quantityScale: $aggregate->quantityScale, rawAmount: $result->rawAmount,
                commitmentAdjustment: $result->commitmentAdjustment, pricedAmount: $result->pricedAmount,
                roundingStage: $price->snapshot->roundingStage->value, roundingMode: $price->snapshot->roundingMode->value,
                roundingPolicyVersion: $price->roundingPolicyVersion, exponentVersion: $price->exponentVersion,
                taxSnapshotRef: $tax->snapshotRef, jurisdiction: $tax->jurisdiction, taxCode: $tax->taxCode, taxMode: $tax->mode, taxRate: $tax->rate,
                netMinor: $amounts['net'], taxMinor: $amounts['tax'], grossMinor: $amounts['gross'], residualMinor: $amounts['residual'],
                intermediatesJson: CanonicalJson::encode(['pricing' => $result, 'tax' => $tax, 'amounts' => $amounts]),
                predecessorBasisRef: $predecessorBasisRef, commitmentScopeDigest: $price->commitmentScopeDigest);
            $net = bcadd($net, RatingRows::decimal($amounts['net']), 0);
            $taxTotal = bcadd($taxTotal, RatingRows::decimal($amounts['tax']), 0);
            $gross = bcadd($gross, RatingRows::decimal($amounts['gross']), 0);
        }
        MoneyFactory::fromMinor(minor: $gross, currency: $inputs->currency, exponent: $inputs->currencyExponent);
        if ($gross === '0' && ! $zeroPrices && $predecessorBasisRef === null) {
            self::refuse('zero_price_not_declared');
        }
        if (bccomp($gross, '0', 0) < 0 || ($gross === '0' && $predecessorBasisRef === null && ($inputs->noChargeReason === null || $inputs->noChargeReason === ''))
            || ($gross !== '0' && $inputs->noChargeReason !== null)) {
            self::refuse('no_charge_reason_required');
        }
        $digest = $inputs->digest();
        $basis = 'rb_'.substr(hash('sha256', implode('|', [$inputs->sellerRef, $inputs->environment, $inputs->occurrenceRef,
            $predecessorBasisRef === null ? 'original' : 'correction:'.$correctionRevision, $digest])), 0, 48);

        return new RatedBasisDraft(basisRef: $basis, inputsDigest: $digest,
            ratedDigest: CanonicalJson::digest(['inputsDigest' => $digest, 'lines' => $lines, 'totals' => ['net' => $net, 'tax' => $taxTotal, 'gross' => $gross]]),
            chargeKind: $gross === '0' ? ChargeKind::NoCharge : ChargeKind::Charge,
            noChargeReason: $gross === '0' && $predecessorBasisRef !== null ? 'correction_zero' : $inputs->noChargeReason,
            lines: $lines, netMinor: $net, taxMinor: $taxTotal, grossMinor: $gross);
    }

    public static function commitmentScope(string $environment, CommitmentTerms $terms): string
    {
        return CanonicalJson::digest([$terms->sellerRef, $environment, $terms->billingAccountRef, $terms->componentRef, $terms->currency, $terms->periodRef]);
    }

    public static function refuse(string $code): never
    {
        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.'.$code);
    }

    private static function declaresZero(PriceVersion $price): bool
    {
        $terms = $price->terms;
        if ($price->commitment !== null) {
            return false;
        }
        if ($terms instanceof FixedTerms) {
            return Dec::parse($terms->amount)->isZero();
        }
        if ($terms instanceof PerUnitTerms) {
            return Dec::parse($terms->unitPrice)->isZero();
        }
        if ($terms instanceof GraduatedTerms || $terms instanceof VolumeTerms) {
            return array_all($terms->tiers, static fn ($tier): bool => Dec::parse($tier->unitPrice)->isZero()
                && Dec::parse($tier->flatFee)->isZero());
        }

        return false;
    }
}
