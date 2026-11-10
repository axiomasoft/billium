<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Pipeline;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\QuantitySnapshot;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenPrice;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\FrozenTax;
use Billium\Rating\Data\Rating\PrepareRating;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxResolutionRequest;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Internal\Adapters\ScheduleOccurrenceAdapter;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Storage\RatingStore;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Data\WindowSelector;
use DateTimeImmutable;
use Throwable;

final readonly class FrozenInputsBuilder
{
    public function __construct(private CallerContext $callers, private ScheduleOccurrenceAdapter $schedule,
        private PriceVersionStore $prices, private TaxSnapshotPort $tax,
        private RatingStore $store, private ?AggregationQueries $usage = null) {}

    public function build(PrepareRating $command): FrozenRatingInputs|RatingHold
    {
        if ($this->store->database()->transactionLevel() !== 0) {
            throw new \LogicException('Rating preparation must run outside the root transaction');
        }
        $occurrence = $this->schedule->occurrence($command->occurrenceRef);
        if (! in_array($occurrence['state'], ['planned', 'rated'], true) || $occurrence['inputs_digest'] !== $command->occurrenceInputsDigest
            || $command->components === [] || count($command->components) > 100) {
            RatingComputation::refuse('occurrence_not_bookable');
        }
        $caller = $this->callers->current();
        if ($occurrence['state'] === 'rated') {
            $existing = $this->store->basis(RatingRows::text($occurrence['rated_basis_ref'] ?? null));
            if ($existing === null) {
                return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [$command->occurrenceRef], detail: 'rated occurrence has no retained basis');
            }
            $retained = $this->store->inputs($existing);
            $pinned = [];
            foreach ($retained->aggregates as $i => $aggregate) {
                $pinned[] = ['componentRef' => $aggregate->componentRef, 'snapshotRef' => FixedOccurrenceInput::matches($aggregate) ? 'fixed' : $aggregate->snapshotRef,
                    'priceRef' => $retained->prices[$i]->priceRef, 'priceVersion' => $retained->prices[$i]->priceVersion];
                if ($aggregate->quantitySnapshot !== null) {
                    $pinned[array_key_last($pinned)]['quantitySnapshot'] = $aggregate->quantitySnapshot;
                }
            }
            $requested = $command->components;
            foreach ($requested as &$component) {
                if (array_key_exists('quantitySnapshot', $component)) {
                    $component['quantitySnapshot'] = self::quantitySnapshot($component['quantitySnapshot']);
                }
            }
            unset($component);
            usort($requested, static fn (array $a, array $b): int => strcmp($a['componentRef'], $b['componentRef']));
            if ($retained->billingAccountRef !== $command->billingAccountRef || $retained->sourceDigest !== $command->sourceDigest
                || $retained->agreementRevision !== $command->agreementRevision || $retained->noChargeReason !== $command->noChargeReason
                || $retained->attribution !== CanonicalJson::encode(json_decode($command->attribution, true, 32, JSON_THROW_ON_ERROR))
                || CanonicalJson::encode($pinned) !== CanonicalJson::encode($requested)) {
                return new RatingHold(reason: HoldReason::FrontierStale, repairOrReplayRefs: [$retained->occurrenceRef], detail: 'immutable basis requires an explicit correction');
            }

            return $retained;
        }
        $start = ScheduleOccurrenceAdapter::micros(RatingRows::text($occurrence['period_start']));
        $end = ScheduleOccurrenceAdapter::micros(RatingRows::text($occurrence['period_end']));
        $components = $command->components;
        usort($components, static fn (array $a, array $b): int => strcmp($a['componentRef'], $b['componentRef']));
        $aggregates = $prices = $taxes = [];
        $currency = null;
        $exponent = null;
        foreach ($components as $component) {
            $price = $this->prices->find(priceRef: $component['priceRef'], version: $component['priceVersion']);
            if ($price === null) {
                return new RatingHold(reason: HoldReason::MissingPrice, repairOrReplayRefs: [$component['priceRef']], detail: 'pinned price version absent');
            }
            $fixed = $component['snapshotRef'] === 'fixed';
            if ($fixed) {
                if (isset($component['quantitySnapshot']) || $component['componentRef'] !== $occurrence['component_ref']) {
                    RatingComputation::refuse('fixed_input_scope_mismatch');
                }
                $group = RatingRows::map(json_decode($command->attribution, true, 32, JSON_THROW_ON_ERROR));
                $group['billingAccountRef'] = $command->billingAccountRef;
                $group['agreementRevision'] = $command->agreementRevision;
                $group['componentRef'] = $component['componentRef'];
                $frozen = FixedOccurrenceInput::freeze(seller: $caller->sellerRef, environment: $caller->environment, occurrence: $command->occurrenceRef,
                    sourceDigest: $command->sourceDigest, occurrenceDigest: $command->occurrenceInputsDigest,
                    start: $start, end: $end, group: $group, price: $price);
            } else {
                if ($this->usage === null) {
                    return new RatingHold(reason: HoldReason::IncompleteSource, repairOrReplayRefs: [$component['snapshotRef']], detail: 'usage capability absent');
                }
                try {
                    $aggregate = RatingRows::map(json_decode(CanonicalJson::encode($this->usage->snapshot($component['snapshotRef'])), true, 128, JSON_THROW_ON_ERROR));
                    $w = RatingRows::map($aggregate['window'] ?? null);
                    $window = new WindowSelector(sellerRef: RatingRows::text($w['sellerRef'] ?? null), environment: RatingRows::text($w['environment'] ?? null),
                        metricRef: RatingRows::text($w['metricRef'] ?? null), metricVersion: RatingRows::text($w['metricVersion'] ?? null), groupKey: RatingRows::map($w['group'] ?? null),
                        startMicros: RatingRows::integer($w['startMicros'] ?? null), endMicros: RatingRows::integer($w['endMicros'] ?? null));
                    $status = $this->usage->windowStatus($window);
                } catch (ProblemException $error) {
                    return new RatingHold(reason: str_contains($error->detail, 'reproduc') ? HoldReason::AggregateNotReproducible : HoldReason::IncompleteSource,
                        repairOrReplayRefs: [$component['snapshotRef']], detail: $error->detail);
                }
                if (($aggregate['reproducibility'] ?? null) !== 'reproducible') {
                    return new RatingHold(reason: HoldReason::AggregateNotReproducible, repairOrReplayRefs: [$component['snapshotRef']], detail: 'retained aggregate unavailable');
                }
                if (($status['state'] ?? null) !== 'final' || ($status['progress'] ?? null) !== 'aggregated'
                    || ($status['finalSnapshotRef'] ?? null) !== $component['snapshotRef']) {
                    return new RatingHold(reason: HoldReason::IncompleteSource, repairOrReplayRefs: RatingRows::texts($status['repairOrReplayRefs'] ?? []), detail: 'source window is not final');
                }
                $group = $window->groupKey;
                if ($window->sellerRef !== $caller->sellerRef || $window->environment !== $caller->environment
                    || $window->startMicros !== $start || $window->endMicros !== $end
                    || ($group['billingAccountRef'] ?? null) !== $command->billingAccountRef
                    || ($group['componentRef'] ?? null) !== $component['componentRef']
                    || ($group['agreementRevision'] ?? null) !== $command->agreementRevision) {
                    RatingComputation::refuse('aggregate_scope_mismatch');
                }
                $frozen = new FrozenAggregate(componentRef: $component['componentRef'], metricRef: $window->metricRef, metricVersion: $window->metricVersion,
                    snapshotRef: RatingRows::text($aggregate['snapshotRef'] ?? null), snapshotDigest: RatingRows::text($aggregate['snapshotDigest'] ?? null),
                    windowStartMicros: $start, windowEndMicros: $end, completenessFrontierDigest: RatingRows::text($aggregate['completenessFrontierDigest'] ?? null),
                    quantity: RatingRows::text($aggregate['quantity'] ?? null), quantityScale: RatingRows::integer($aggregate['quantityScale'] ?? null), snapshot: $aggregate,
                    quantitySnapshot: isset($component['quantitySnapshot']) ? self::quantitySnapshot($component['quantitySnapshot']) : null);
            }
            if ($currency !== null && ($price->currency !== $currency || $price->currencyExponent !== $exponent)) {
                return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'mixed currency is unsupported');
            }
            $currency = $price->currency;
            $exponent = $price->currencyExponent;
            $taxInputDigest = CanonicalJson::digest([$caller->sellerRef, $caller->environment, $command, $component, $price->termsDigest]);
            try {
                $tax = $this->tax->resolve(new TaxResolutionRequest(sellerRef: $caller->sellerRef, environment: $caller->environment,
                    billingAccountRef: $command->billingAccountRef, jurisdictionHint: $command->jurisdictionHint,
                    componentRef: $component['componentRef'], currency: $currency, taxPoint: new DateTimeImmutable(RatingRows::text($occurrence['period_end'])),
                    inputDigest: $taxInputDigest));
                if ($tax->inputDigest !== $taxInputDigest) {
                    RatingComputation::refuse('tax_input_mismatch');
                }
            } catch (Throwable) {
                return new RatingHold(reason: HoldReason::MissingTax, repairOrReplayRefs: [], detail: 'tax resolution failed');
            }
            $scope = $price->commitment === null ? null : RatingComputation::commitmentScope(environment: $caller->environment, terms: $price->commitment);
            $basis = $scope === null ? null : $this->store->commitment($scope);
            $prices[] = new FrozenPrice(componentRef: $component['componentRef'], priceRef: $price->priceRef, priceVersion: $price->version,
                strategyId: $price->strategyId->value, strategyVersion: $price->strategyVersion, termsDigest: $price->termsDigest,
                commitmentScopeDigest: $scope, commitmentBasisDigest: $basis['basisDigest'] ?? null, alreadyBilledNetMinor: $basis['alreadyBilledNetMinor'] ?? null,
                roundingPolicyVersion: $price->roundingPolicyVersion, exponentVersion: $price->exponentVersion, snapshot: $price);
            $taxes[] = new FrozenTax(snapshotRef: $tax->snapshotRef, snapshotDigest: CanonicalJson::digest($tax), inputDigest: $tax->inputDigest,
                jurisdiction: $tax->jurisdiction, taxCode: $tax->taxCode, mode: $tax->mode->value, rate: $tax->rate, snapshot: $tax);
            $aggregates[] = $frozen;
        }
        $head = $this->store->frontier($command->occurrenceRef);

        return new FrozenRatingInputs(sellerRef: $caller->sellerRef, environment: $caller->environment, billingAccountRef: $command->billingAccountRef,
            occurrenceRef: $command->occurrenceRef, periodStartMicros: $start, periodEndMicros: $end,
            attribution: CanonicalJson::encode(json_decode($command->attribution, true, 32, JSON_THROW_ON_ERROR)),
            agreementRevision: $command->agreementRevision, currency: $currency, currencyExponent: $exponent,
            aggregates: $aggregates, prices: $prices, taxes: $taxes, occurrenceInputsDigest: $command->occurrenceInputsDigest,
            sourceDigest: $command->sourceDigest, frontierExpectedDigest: $head['headDigest'], frontierExpectedRowVersion: $head['rowVersion'], noChargeReason: $command->noChargeReason);
    }

    /** @return array<string, mixed> */
    private static function quantitySnapshot(mixed $value): array
    {
        if (! $value instanceof QuantitySnapshot) {
            RatingComputation::refuse('quantity_snapshot_invalid');
        }
        $codec = new Codec;
        $json = $codec->encode($value);
        if (! $codec->decode($json) instanceof QuantitySnapshot) {
            RatingComputation::refuse('quantity_snapshot_invalid');
        }

        return RatingRows::map(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
    }
}
