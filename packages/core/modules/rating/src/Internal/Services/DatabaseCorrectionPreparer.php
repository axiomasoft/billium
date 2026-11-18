<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Services;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Data\Correction\CorrectionCreditPart;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenTax;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxCorrectionRequest;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Enums\ReleasePolicy;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Adapters\LedgerAdapter;
use Billium\Rating\Internal\Adapters\ScheduleOccurrenceAdapter;
use Billium\Rating\Internal\Adapters\UsageAggregateAdapter;
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
use Billium\Schedule\Enums\OccurrenceState;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class DatabaseCorrectionPreparer
{
    public function __construct(private CallerContext $callers, private RatingStore $store, private CorrectionStore $corrections, private ScheduleOccurrenceAdapter $schedule,
        private TaxSnapshotPort $tax, private TaxPolicyFrontier $policyFrontier, private RatingComputation $rating,
        private CorrectionCalculator $calculator, private AmendmentSupport $amendmentSupport, private ProrationCalculator $proration,
        private UsageAggregateAdapter $usage, private LedgerAdapter $ledger) {}

    public function prepare(PrepareCorrection $command): PreparedCorrection|RatingHold
    {
        if ($this->store->database()->transactionLevel() !== 0) {
            throw new LogicException('Correction preparation must run outside a transaction');
        }
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $command->sellerRef || $caller->environment !== $command->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.rating.scope_denied');
        }
        $occurrence = $this->schedule->find($command->parentOccurrenceRef);
        if ($occurrence === null || ! in_array($occurrence->state, [OccurrenceState::Obligated, OccurrenceState::Rated], true) || $command->reasonCode === '') {
            RatingComputation::refuse('correction_not_applicable');
        }
        $original = $this->store->basis(RatingRows::text($occurrence->ratedBasisRef));
        $booking = $original === null ? null : $this->store->booking(RatingRows::integer($original->id));
        if ($original === null || $booking === null
            || ! ($occurrence->state === OccurrenceState::Obligated && $booking->outcome === 'booked' && is_string($booking->obligation_ref)) && ! ($occurrence->state === OccurrenceState::Rated && $booking->outcome === 'no_charge' && $booking->obligation_ref === null && $original->charge_kind === 'no_charge' && RatingRows::decimal($original->gross_minor) === '0')) {
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
            $ratio = $this->proration->fraction(policy: $amendment->prorationPolicy, start: $occurrence->periodStart,
                end: $occurrence->periodEnd, effectiveAt: $amendment->effectiveAt, timezone: $amendment->timezone);
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
            if (! $this->usage->available()) {
                return new RatingHold(reason: HoldReason::IncompleteSource, repairOrReplayRefs: [$aggregate->snapshotRef], detail: 'usage capability absent');
            }
            $selector = $this->usage->window(sellerRef: $retained->sellerRef, environment: $retained->environment, aggregate: $aggregate);
            $status = $this->usage->windowStatus($selector);
            if ($status['lagFacts'] > 0) {
                $held = $this->usage->latePolicyHold($selector);
                if ($held !== null) {
                    return $held;
                }
            }
            if ($status['state'] !== 'final' || $status['progress'] !== 'aggregated' || $status['finalSnapshotRef'] === null) {
                return new RatingHold(reason: HoldReason::IncompleteSource, repairOrReplayRefs: [], detail: 'billium.rating.source_incomplete');
            }
            $snapshot = $this->usage->snapshot($status['finalSnapshotRef']);
            if (($snapshot['snapshotRef'] ?? null) !== $aggregate->snapshotRef) {
                $held = $this->usage->latePolicyHold($selector);
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
        $oldTaxes = array_map(static fn (FrozenTax $tax): TaxSnapshot => $tax->snapshot, $originalInputs->taxes);
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
        $obligation = $booking->obligation_ref === null ? null : $this->ledger->obligation($booking->obligation_ref);
        $invoiceRef = $obligation?->billedInvoiceRef;
        $creditParts = [];
        if (bccomp(RatingRows::decimal($computation->deltaGrossMinor), '0', 0) < 0) {
            $remaining = bcsub('0', RatingRows::decimal($computation->deltaGrossMinor), 0);
            $obligationRefs = $booking->obligation_ref === null ? [] : [$booking->obligation_ref];
            foreach ([...$obligationRefs, ...$this->corrections->childObligations($command->parentOccurrenceRef)] as $ref) {
                $view = $ref === $booking->obligation_ref && $obligation !== null ? $obligation : $this->ledger->obligation($ref);
                $amount = $view->creditableMinor(openOnly: $command->releasePolicy === ReleasePolicy::OpenOnly);
                if (bccomp($amount, '0', 0) <= 0) {
                    continue;
                }
                $invoice = $view->creditableInvoiceRef();
                if ($invoice === null) {
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
}
