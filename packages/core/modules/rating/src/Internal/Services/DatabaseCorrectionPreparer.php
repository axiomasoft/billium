<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Services;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\CallerContext;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\CorrectionCreditPart;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Enums\ReleasePolicy;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Corrections\CorrectionCalculator;
use Billium\Rating\Internal\Corrections\CorrectionDigest;
use Billium\Rating\Internal\Corrections\CorrectionInputs;
use Billium\Rating\Internal\Corrections\TaxCorrectionGroup;
use Billium\Rating\Internal\Pipeline\FixedOccurrenceInput;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Proration\AmendmentSupport;
use Billium\Rating\Internal\Proration\ProrationCalculator;
use Billium\Rating\Internal\Proration\QuantitySnapshotScope;
use Billium\Rating\Internal\Storage\CorrectionStore;
use Billium\Rating\Internal\Storage\FrozenInputsCodec;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Storage\RatingStore;
use Billium\Rating\Internal\Storage\TaxPolicyFrontier;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LatePolicy;
use DateTimeImmutable;
use Throwable;

final readonly class DatabaseCorrectionPreparer
{
    public function __construct(private CallerContext $callers, private RatingStore $store, private CorrectionStore $corrections, private ScheduleQueries $schedule,
        private LedgerQueries $ledger, private TaxSnapshotPort $tax,
        private TaxPolicyFrontier $policyFrontier, private RatingComputation $rating, private CorrectionCalculator $calculator,
        private AmendmentSupport $amendmentSupport, private ProrationCalculator $proration, private ?AggregationQueries $usage = null) {}

    public function prepare(PrepareCorrection $command): PreparedCorrection|RatingHold
    {
        if ($this->store->database()->transactionLevel() !== 0) {
            throw new \LogicException('Correction preparation must run outside a transaction');
        }
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $command->sellerRef || $caller->environment !== $command->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.rating.scope_denied');
        }
        $occurrence = $this->schedule->occurrence($command->parentOccurrenceRef);
        if ($occurrence === null || ($occurrence['state'] ?? null) !== 'obligated' || $command->reasonCode === '') {
            RatingComputation::refuse('correction_not_applicable');
        }
        $original = $this->store->basis(RatingRows::text($occurrence['rated_basis_ref'] ?? null));
        $booking = $original === null ? null : $this->store->booking(RatingRows::integer($original->id));
        if ($original === null || $booking === null || ! is_string($booking->obligation_ref)) {
            RatingComputation::refuse('correction_not_applicable');
        }
        $head = $this->store->frontier($command->parentOccurrenceRef);
        $previous = $head['headBasisRef'] === null ? null : $this->store->basis($head['headBasisRef']);
        if ($previous === null) {
            RatingComputation::refuse('correction_not_applicable');
        }
        $retained = $this->store->inputs($previous);
        $predecessor = $this->store->draft($previous);
        $originalInputs = $this->store->inputs($original);
        $originalDraft = $this->store->draft($original);
        $amendment = $command->amendment;
        $fraction = $retained->proration;
        $effectiveAt = $command->correctionEffectiveDate;
        if ($command->kind === CorrectionKind::PartialCancel) {
            if ($amendment === null || $amendment->amendmentRef === '' || $amendment->revision < 1) {
                RatingComputation::refuse('amendment_unsupported');
            }
            $this->amendmentSupport->assertRevision(previous: $retained->proration, amendment: $amendment);
            foreach ($retained->prices as $i => $price) {
                $this->amendmentSupport->assertSupported(kind: $amendment->kind, price: $price->snapshot,
                    quantitySnapshot: $retained->aggregates[$i]->quantitySnapshot);
            }
            $periodStart = new DateTimeImmutable(RatingRows::text($occurrence['period_start']));
            $periodEnd = new DateTimeImmutable(RatingRows::text($occurrence['period_end']));
            $ratio = $this->proration->fraction(policy: $amendment->prorationPolicy, start: $periodStart,
                end: $periodEnd, effectiveAt: $amendment->effectiveAt, timezone: $amendment->timezone);
            $fraction = [...$ratio, 'amendmentRef' => $amendment->amendmentRef, 'revision' => $amendment->revision,
                'effectiveAt' => $amendment->effectiveAt->format('Y-m-d\TH:i:s.uP'), 'policy' => $amendment->prorationPolicy->value,
                'timezone' => $amendment->timezone];
            $effectiveAt ??= $amendment->effectiveAt;
        } elseif ($amendment !== null) {
            RatingComputation::refuse('amendment_unsupported');
        }
        if ($effectiveAt === null) {
            RatingComputation::refuse('terms_invalid');
        }
        $aggregates = [];
        foreach ($retained->aggregates as $i => $aggregate) {
            if (($aggregate->snapshot['kind'] ?? null) === 'approved_quantity/1') {
                QuantitySnapshotScope::assertValid(inputs: $retained, aggregate: $aggregate, price: $retained->prices[$i]->snapshot);
                $aggregates[] = $aggregate;

                continue;
            }
            if (FixedOccurrenceInput::matches($aggregate)) {
                // A correction frontier changes independently of the retained fixed source evidence.
                $fixedInputs = $retained->correctionEvidence === null ? $retained : FrozenInputsCodec::decode(CanonicalJson::encode([
                    ...get_object_vars($retained), 'sourceDigest' => RatingRows::text($aggregate->snapshot['sourceDigest'] ?? null)]));
                FixedOccurrenceInput::assertValid(inputs: $fixedInputs, aggregate: $aggregate, price: $retained->prices[$i]->snapshot);
                $aggregates[] = $aggregate;

                continue;
            }
            if ($this->usage === null) {
                return new RatingHold(reason: HoldReason::IncompleteSource, repairOrReplayRefs: [$aggregate->snapshotRef], detail: 'usage capability absent');
            }
            $window = RatingRows::map($aggregate->snapshot['window'] ?? null);
            $selector = new WindowSelector(sellerRef: $retained->sellerRef, environment: $retained->environment,
                metricRef: $aggregate->metricRef, metricVersion: $aggregate->metricVersion, groupKey: RatingRows::map($window['group'] ?? null),
                startMicros: $aggregate->windowStartMicros, endMicros: $aggregate->windowEndMicros);
            $status = $this->usage->windowStatus($selector);
            if (($status['lagFacts'] ?? 0) > 0) {
                $held = $this->latePolicyHold($selector);
                if ($held !== null) {
                    return $held;
                }
            }
            if (($status['state'] ?? null) !== 'final' || ($status['progress'] ?? null) !== 'aggregated' || ! is_string($status['finalSnapshotRef'] ?? null)) {
                return new RatingHold(reason: HoldReason::IncompleteSource, repairOrReplayRefs: [], detail: 'billium.rating.source_incomplete');
            }
            $snapshot = RatingRows::map(json_decode(CanonicalJson::encode($this->usage->snapshot($status['finalSnapshotRef'])), true, 128, JSON_THROW_ON_ERROR));
            if (($snapshot['snapshotRef'] ?? null) !== $aggregate->snapshotRef) {
                $held = $this->latePolicyHold($selector);
                if ($held !== null) {
                    return $held;
                }
            }
            $aggregates[] = new FrozenAggregate(componentRef: $aggregate->componentRef, metricRef: $aggregate->metricRef,
                metricVersion: $aggregate->metricVersion, snapshotRef: RatingRows::text($snapshot['snapshotRef'] ?? null),
                snapshotDigest: RatingRows::text($snapshot['snapshotDigest'] ?? null), windowStartMicros: $aggregate->windowStartMicros,
                windowEndMicros: $aggregate->windowEndMicros, completenessFrontierDigest: RatingRows::text($snapshot['completenessFrontierDigest'] ?? null),
                quantity: RatingRows::text($snapshot['quantity'] ?? null), quantityScale: RatingRows::integer($snapshot['quantityScale'] ?? null), snapshot: $snapshot,
                quantitySnapshot: $aggregate->quantitySnapshot);
        }
        $sourceDigests = array_map(static fn (FrozenAggregate $a): string => $a->snapshotDigest, $aggregates);
        $oldTaxes = array_map(static fn ($tax) => $tax->snapshot, $originalInputs->taxes);
        $preliminaryInputs = CorrectionInputs::replace(old: $retained, aggregates: $aggregates, taxes: $oldTaxes,
            sourceDigest: CanonicalJson::digest($sourceDigests), version: $head['rowVersion'], frontierDigest: $head['headDigest'], proration: $fraction);
        $candidate = $this->rating->compute(inputs: $preliminaryInputs, proration: $preliminaryInputs->proration,
            predecessorBasisRef: $predecessor->basisRef, correctionRevision: $head['correctionRevision'] + 1);
        $originalTax = $originalInputs->taxes[0]->snapshot;
        if (! TaxCorrectionGroup::isHomogeneous($oldTaxes)) {
            return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.rating.tax_policy_unsupported');
        }
        $inputDigest = CanonicalJson::digest([$command->sellerRef, $command->environment, $command->parentOccurrenceRef,
            $originalTax, $effectiveAt, $command->reasonCode, $originalDraft->netMinor, $candidate->netMinor, $sourceDigests]);
        $request = new TaxCorrectionRequest(sellerRef: $command->sellerRef, environment: $command->environment,
            parentOccurrenceRef: $command->parentOccurrenceRef, originalTaxSnapshotRef: $originalTax->snapshotRef,
            originalTaxSnapshotDigest: CanonicalJson::digest($originalTax), originalTaxPoint: $originalTax->taxPoint,
            correctionEffectiveDate: $effectiveAt, reasonCode: $command->reasonCode, jurisdiction: $originalTax->jurisdiction,
            originalTaxableBaseMinor: $originalDraft->netMinor, correctedTaxableBaseMinor: $candidate->netMinor, inputDigest: $inputDigest);
        try {
            $policy = $this->tax->resolveCorrection($request);
        } catch (Throwable) {
            return new RatingHold(reason: HoldReason::MissingTax, repairOrReplayRefs: [], detail: 'billium.rating.tax_policy_missing');
        }
        if (! in_array($policy->treatment, ['original_rate', 'current_legal_rate', 'other_supported'], true)
            || $policy->selectedRate->kind !== TaxSnapshotKind::Correction || $policy->selectedRate->jurisdiction !== $originalTax->jurisdiction
            || $policy->selectedRate->taxCode !== $originalTax->taxCode) {
            return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.rating.tax_policy_unsupported');
        }
        $policyHead = $this->policyFrontier->head($policy);
        if ($policy->inputDigest !== $inputDigest || $policy->policyRef === '' || $policy->policyVersion === ''
            || $policy->outputDigest === '' || $policy->resolverVersion === '' || $effectiveAt < $policy->effectiveFrom
            || ($policy->effectiveTo !== null && $effectiveAt > $policy->effectiveTo)
            || $policyHead['digest'] !== TaxPolicyFrontier::digest($policy)) {
            return new RatingHold(reason: HoldReason::FrontierStale, repairOrReplayRefs: [], detail: 'billium.rating.tax_policy_stale');
        }
        $frontier = CorrectionDigest::frontier(sourceSnapshotDigests: $sourceDigests, policy: $policy,
            amendmentDigest: $fraction === null ? null : CanonicalJson::digest($fraction));
        $inputs = CorrectionInputs::replace(old: $retained, aggregates: $aggregates,
            taxes: array_fill(0, count($aggregates), $policy->selectedRate), sourceDigest: $frontier,
            version: $head['rowVersion'], frontierDigest: $head['headDigest'], proration: $fraction,
            correctionEvidence: ['request' => json_decode(CanonicalJson::encode($request), true, 64, JSON_THROW_ON_ERROR),
                'policy' => json_decode(CanonicalJson::encode($policy), true, 64, JSON_THROW_ON_ERROR)]);
        try {
            $computation = $this->calculator->compute(inputs: $inputs, predecessor: $predecessor,
                revision: $head['correctionRevision'] + 1, policy: $policy, request: $request);
        } catch (ProblemException $e) {
            if (str_contains($e->detail, 'correction_mixed_sign')) {
                return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: $e->detail);
            }
            throw $e;
        }
        if ($computation->correctedBasis->netMinor !== $request->correctedTaxableBaseMinor) {
            return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.rating.tax_policy_unsupported');
        }
        if (bccomp(RatingRows::decimal($computation->deltaGrossMinor), '0', 0) < 0 && $command->releasePolicy === null) {
            RatingComputation::refuse('release_policy_required');
        }
        $obligation = $this->ledger->obligation($booking->obligation_ref);
        $invoiceRef = is_string($obligation['billedInvoiceRef'] ?? null) ? $obligation['billedInvoiceRef'] : null;
        $creditParts = [];
        if (bccomp(RatingRows::decimal($computation->deltaGrossMinor), '0', 0) < 0) {
            $remaining = bcsub('0', RatingRows::decimal($computation->deltaGrossMinor), 0);
            foreach ([$booking->obligation_ref, ...$this->corrections->childObligations($command->parentOccurrenceRef)] as $ref) {
                $view = $ref === $booking->obligation_ref ? $obligation : $this->ledger->obligation($ref);
                $amount = $command->releasePolicy === ReleasePolicy::OpenOnly
                    ? (string) Money::fromWire($view['open'] ?? null)->minor
                    : bcsub(bcadd((string) Money::fromWire($view['originalDue'] ?? null)->minor,
                        (string) Money::fromWire($view['adjusted'] ?? null)->minor, 0), (string) Money::fromWire($view['credited'] ?? null)->minor, 0);
                if (bccomp($amount, '0', 0) <= 0) {
                    continue;
                }
                $invoice = $view['billedInvoiceRef'] ?? null;
                if (! is_string($invoice) || ! in_array($view['billedInvoiceStatus'] ?? null, ['issued', 'partially_paid', 'paid', 'uncollectible'], true)) {
                    return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.rating.invoice_not_issued');
                }
                $part = bccomp($amount, $remaining, 0) < 0 ? $amount : $remaining;
                $creditParts[] = new CorrectionCreditPart(obligationRef: $ref, invoiceRef: $invoice, amountMinor: $part);
                $remaining = bcsub($remaining, $part, 0);
                if (bccomp($remaining, '0', 0) === 0) {
                    break;
                }
            }
            if (bccomp($remaining, '0', 0) > 0) {
                return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.rating.credit_capacity_insufficient');
            }
        }
        $schedule = $this->schedule->correctionHead(sellerRef: $command->sellerRef, parentOccurrenceRef: $command->parentOccurrenceRef);

        return new PreparedCorrection(inputs: $inputs, computation: $computation, taxPolicy: $policy, taxRequest: $request,
            expectedScheduleHeadRevision: $schedule['revision'], expectedScheduleHeadFrontierDigest: $schedule['frontierDigest'],
            expectedFrontierRowVersion: $head['rowVersion'], expectedFrontierDigest: $head['headDigest'], releasePolicy: $command->releasePolicy,
            command: $command, originalObligationRef: $booking->obligation_ref, invoiceRef: $invoiceRef,
            originalBasisRef: RatingRows::text($original->basis_ref), expectedPolicyRowVersion: $policyHead['version'], creditParts: $creditParts);
    }

    private function latePolicyHold(WindowSelector $selector): ?RatingHold
    {
        $policy = ($this->usage ?? RatingComputation::refuse('usage_capability_absent'))->latePolicy($selector);
        if ($policy['policy'] === LatePolicy::Reject) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.usage.late_rejected');
        }
        if ($policy['policy'] === LatePolicy::ManualReview) {
            return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.usage.late_manual_review');
        }
        if (! $policy['reopenAllowed']) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.usage.late_rejected');
        }

        return null;
    }
}
