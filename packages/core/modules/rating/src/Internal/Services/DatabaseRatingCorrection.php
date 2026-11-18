<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Services;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Obligations\CorrectionBasis;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Data\Correction\ApplyCorrection;
use Billium\Rating\Data\Correction\PrepareCorrection;
use Billium\Rating\Data\Correction\PreparedCorrection;
use Billium\Rating\Data\Correction\RatingCorrectionReceipt;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenTax;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Enums\CorrectionKind;
use Billium\Rating\Enums\TaxSnapshotKind;
use Billium\Rating\Internal\Adapters\LedgerAdapter;
use Billium\Rating\Internal\Adapters\ObligationCommandMapper;
use Billium\Rating\Internal\Adapters\OutboxAdapter;
use Billium\Rating\Internal\Adapters\ScheduleOccurrenceAdapter;
use Billium\Rating\Internal\Adapters\UsageAggregateAdapter;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Corrections\CorrectionCalculator;
use Billium\Rating\Internal\Corrections\CorrectionDigest;
use Billium\Rating\Internal\Corrections\CorrectionInputs;
use Billium\Rating\Internal\Corrections\TaxCorrectionGroup;
use Billium\Rating\Internal\Money\MoneyFactory;
use Billium\Rating\Internal\Pipeline\FixedOccurrenceInput;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Proration\AmendmentSupport;
use Billium\Rating\Internal\Proration\ProrationCalculator;
use Billium\Rating\Internal\Storage\CorrectionStore;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Storage\RatingStore;
use Billium\Rating\Internal\Storage\TaxPolicyFrontier;
use Billium\Rating\Internal\Time\Micros;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Enums\OccurrenceState;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseRatingCorrection implements RatingCorrection
{
    public function __construct(private DatabaseCorrectionPreparer $preparer, private OperationRunner $runner,
        private CallerContext $callers, private RatingStore $store, private CorrectionStore $corrections,
        private CorrectionCalculator $calculator, private TaxPolicyFrontier $policyFrontier,
        private UsageAggregateAdapter $usage, private ScheduleOccurrenceAdapter $occurrences,
        private LedgerAdapter $ledger, private ObligationCommandMapper $obligations, private OutboxAdapter $outbox,
        private RatingComputation $rating, private ProrationCalculator $proration, private AmendmentSupport $amendmentSupport) {}

    public function prepare(PrepareCorrection $command): PreparedCorrection|RatingHold
    {
        return $this->preparer->prepare($command);
    }

    public function apply(ApplyCorrection $command): RatingCorrectionReceipt
    {
        $p = $command->prepared;
        $in = $p->inputs;
        $c = $p->computation;
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $in->sellerRef || $caller->environment !== $in->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.rating.scope_denied');
        }
        $semantic = CanonicalJson::digest($p);
        $modules = ['platform', 'rating', 'schedule', 'ledger'];
        if (array_any($in->aggregates, static fn (FrozenAggregate $aggregate): bool => ! FixedOccurrenceInput::matches($aggregate))) {
            $modules[] = 'usage';
        }
        $outcome = $this->runner->run(spec: new OperationSpec(commandKind: 'rating.correct.v1', sellerRef: $in->sellerRef,
            environment: $in->environment, mode: FinancialMode::Active, operationClass: ModuleOperationClass::NewIntent,
            modules: $modules, commandKey: $command->operationKey, digest: $semantic,
            commitKind: 'rating.correct.v1'), body: function (TransactionContext $context) use ($p, $in, $c): RatingCorrectionReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'rating');
                $this->usage->lock(context: $context, inputs: $in);
                $this->store->lockFrontiers(context: $context, inputs: $in, additionalKeys: [TaxPolicyFrontier::key($p->taxPolicy->policyRef)]);
                $existing = $this->corrections->byFrontier(context: $context, parent: $in->occurrenceRef, digest: $c->sourceFrontierDigest);
                if ($existing !== null) {
                    if (($existing->correction_kind === CorrectionKind::PartialCancel->value) !== ($p->command->amendment !== null)) {
                        RatingComputation::refuse('correction_digest_mismatch');
                    }
                    if ($p->command->amendment !== null) {
                        $existingBasis = $this->store->query('rated_basis', $context)->where('id', $existing->basis_id)->first();
                        if ($existingBasis === null) {
                            RatingComputation::refuse('correction_digest_mismatch');
                        }
                        $recorded = $this->store->inputs($existingBasis)->proration;
                        if (($recorded['revision'] ?? null) !== $p->command->amendment->revision) {
                            RatingComputation::refuse('correction_digest_mismatch');
                        }
                        $this->amendmentSupport->assertRevision(previous: $recorded, amendment: $p->command->amendment);
                    }

                    return $this->corrections->receipt(context: $context, correction: $existing, currency: $in->currency);
                }
                $head = $this->store->frontier(occurrenceRef: $in->occurrenceRef, context: $context);
                if ($head['rowVersion'] !== $p->expectedFrontierRowVersion || $head['headDigest'] !== $p->expectedFrontierDigest
                    || $head['headBasisRef'] !== $c->predecessorBasisRef || $c->correctionRevision !== $head['correctionRevision'] + 1) {
                    RatingComputation::refuse('correction_frontier_stale');
                }
                $this->assertPinned(context: $context, p: $p);
                $this->usage->assertCurrent(context: $context, inputs: $in);
                $previous = $this->store->basis(ref: $c->predecessorBasisRef, context: $context);
                if ($previous === null || CanonicalJson::encode($this->calculator->compute(inputs: $in, predecessor: $this->store->draft($previous),
                    revision: $c->correctionRevision, policy: $p->taxPolicy, request: $p->taxRequest)) !== CanonicalJson::encode($c)) {
                    RatingComputation::refuse('correction_digest_mismatch');
                }
                $this->occurrences->lock(context: $context, ref: $in->occurrenceRef);
                $basis = CorrectionBasis::derive(originalBasisRef: 'rating:'.$c->predecessorBasisRef,
                    correctionRef: $in->occurrenceRef, revision: $c->correctionRevision);
                $child = $this->occurrences->registerCorrectionChild(context: $context, command: new RegisterCorrectionChild(
                    sellerRef: $in->sellerRef, environment: $in->environment, parentOccurrenceRef: $in->occurrenceRef,
                    originalPeriodStart: Micros::instant($in->periodStartMicros), originalPeriodEnd: Micros::instant($in->periodEndMicros),
                    correctionRevision: $c->correctionRevision, predecessorRevision: $c->correctionRevision === 1 ? null : $c->correctionRevision - 1,
                    predecessorRatedBasisRef: $c->predecessorBasisRef, sourceFrontierDigest: $c->sourceFrontierDigest,
                    expectedHeadRevision: $p->expectedScheduleHeadRevision, expectedHeadFrontierDigest: $p->expectedScheduleHeadFrontierDigest,
                    taxCorrectionPolicyRef: $p->taxPolicy->policyRef, taxCorrectionPolicyVersion: $p->taxPolicy->policyVersion,
                    reasonCode: $p->command->reasonCode, obligationBasisRef: $basis));
                $sign = bccomp(RatingRows::decimal($c->deltaGrossMinor), '0', 0);
                $branch = $sign > 0 ? 'obligation' : ($sign < 0 ? 'credit_note' : 'none');
                $rows = $this->corrections->insert(context: $context, prepared: $p, childRef: $child->correctionRef, branch: $branch);
                $obligationRef = $creditNoteRef = null;
                if ($sign > 0) {
                    $command = $this->obligations->mapCorrection(in: $in, correctionRef: $child->correctionRef, basisRef: $basis,
                        deltaGrossMinor: $c->deltaGrossMinor, correctedBasisRef: $c->correctedBasis->basisRef,
                        taxSnapshotRef: $p->taxPolicy->selectedRate->snapshotRef);
                    $obligationRef = $command->obligationRef;
                    $this->ledger->recordCorrection(context: $context, command: $command, parentObligationRef: $p->originalObligationRef);
                } elseif ($sign < 0) {
                    if ($p->releasePolicy === null) {
                        RatingComputation::refuse('release_policy_required');
                    }
                    $creditNoteRef = 'rating:'.$child->correctionRef;
                    $allowed = [$p->originalObligationRef, ...$this->corrections->childObligations($in->occurrenceRef, $context)];
                    $groups = [];
                    $total = '0';
                    $seen = [];
                    foreach ($p->creditParts as $part) {
                        if (! in_array($part->obligationRef, $allowed, true) || isset($seen[$part->obligationRef])
                            || preg_match('/^[1-9][0-9]*$/D', $part->amountMinor) !== 1 || $part->invoiceRef === '') {
                            RatingComputation::refuse('correction_digest_mismatch');
                        }
                        $seen[$part->obligationRef] = true;
                        $total = bcadd($total, RatingRows::decimal($part->amountMinor), 0);
                        $groups[$part->invoiceRef][] = new CreditNoteLine(obligationRef: $part->obligationRef,
                            money: MoneyFactory::fromMinor(minor: $part->amountMinor, currency: $in->currency, exponent: $in->currencyExponent));
                    }
                    if ($groups === [] || bccomp($total, bcsub('0', RatingRows::decimal($c->deltaGrossMinor), 0), 0) !== 0) {
                        RatingComputation::refuse('correction_digest_mismatch');
                    }
                    ksort($groups, SORT_STRING);
                    $commands = [];
                    foreach ($groups as $invoice => $lines) {
                        $ref = count($groups) === 1 ? $creditNoteRef : $creditNoteRef.':'.substr(hash('sha256', $invoice), 0, 16);
                        $commands[] = new IssueCreditNote(commandKey: $ref, scope: ObligationCommandMapper::scope($in), creditNoteRef: $ref, invoiceRef: $invoice,
                            mode: CreditNoteMode::from($p->releasePolicy->value), lines: $lines, reason: $p->command->reasonCode);
                    }
                    $this->ledger->issueCreditNotes(context: $context, commands: $commands);
                    if (count($commands) > 1) {
                        $suffixes = array_map(static fn (IssueCreditNote $item): string => substr($item->creditNoteRef, -16), $commands);
                        $creditNoteRef .= ':group:'.implode('.', $suffixes);
                    }
                }
                $this->corrections->book(context: $context, id: $rows['correctionId'], obligation: $obligationRef,
                    creditNote: $creditNoteRef, release: $sign < 0 ? $p->releasePolicy->value : null);
                $this->corrections->advance(context: $context, prepared: $p);
                $receipt = new RatingCorrectionReceipt(correctionRef: $child->correctionRef, correctionRevision: $c->correctionRevision,
                    outcome: $branch, deltaGrossMinor: $c->deltaGrossMinor, obligationRef: $obligationRef, creditNoteRef: $creditNoteRef,
                    currency: $in->currency, legalRequirementPending: $branch !== 'none');
                $this->outbox->correctionApplied(context: $context, receipt: $receipt);

                return $receipt;
            })->orThrow();

        return RatingCorrectionReceipt::fromBody($outcome->body);
    }

    private function assertPinned(TransactionContext $context, PreparedCorrection $p): void
    {
        $policy = $p->taxPolicy;
        $request = $p->taxRequest;
        $in = $p->inputs;
        $head = $this->policyFrontier->head(policy: $policy, context: $context);
        $digests = array_map(static fn (FrozenAggregate $aggregate): string => $aggregate->snapshotDigest, $in->aggregates);
        $expectedFrontier = CorrectionDigest::frontier(sourceSnapshotDigests: $digests, policy: $policy,
            amendmentDigest: $in->proration === null ? null : CanonicalJson::digest($in->proration));
        $original = $this->store->basis(ref: $p->originalBasisRef, context: $context);
        $previous = $this->store->basis(ref: $p->computation->predecessorBasisRef, context: $context);
        if ($original === null || $previous === null || $original->kind !== 'original'
            || $original->occurrence_ref !== $in->occurrenceRef || $previous->occurrence_ref !== $in->occurrenceRef) {
            RatingComputation::refuse('correction_digest_mismatch');
        }
        $booking = $this->store->booking(id: RatingRows::integer($original->id), context: $context);
        $old = $this->store->inputs($previous);
        $originalInputs = $this->store->inputs($original);
        $originalDraft = $this->store->draft($original);
        $occurrence = $this->occurrences->occurrence($in->occurrenceRef);
        if ($booking === null
            || ! ($occurrence->state === OccurrenceState::Obligated && $booking->outcome === 'booked' && is_string($booking->obligation_ref)) && ! ($occurrence->state === OccurrenceState::Rated && $booking->outcome === 'no_charge' && $booking->obligation_ref === null && $originalDraft->chargeKind->value === 'no_charge' && $originalDraft->grossMinor === '0')
            || $booking->obligation_ref !== $p->originalObligationRef || $p->command->sellerRef !== $in->sellerRef
            || $p->command->environment !== $in->environment || $p->command->parentOccurrenceRef !== $in->occurrenceRef
            || $p->releasePolicy !== $p->command->releasePolicy
            || CanonicalJson::encode($old->prices) !== CanonicalJson::encode($in->prices)
            || [$old->sellerRef, $old->environment, $old->billingAccountRef, $old->occurrenceRef, $old->periodStartMicros,
                $old->periodEndMicros, $old->attribution, $old->agreementRevision, $old->currency, $old->currencyExponent, $old->occurrenceInputsDigest]
                !== [$in->sellerRef, $in->environment, $in->billingAccountRef, $in->occurrenceRef, $in->periodStartMicros,
                    $in->periodEndMicros, $in->attribution, $in->agreementRevision, $in->currency, $in->currencyExponent, $in->occurrenceInputsDigest]) {
            RatingComputation::refuse('correction_digest_mismatch');
        }
        if (count($old->aggregates) !== count($in->aggregates)) {
            RatingComputation::refuse('correction_digest_mismatch');
        }
        foreach ($old->aggregates as $index => $oldAggregate) {
            $aggregate = $in->aggregates[$index];
            $oldWindow = RatingRows::map($oldAggregate->snapshot['window'] ?? null);
            $window = RatingRows::map($aggregate->snapshot['window'] ?? null);
            if ([$oldAggregate->componentRef, $oldAggregate->metricRef, $oldAggregate->metricVersion,
                $oldAggregate->windowStartMicros, $oldAggregate->windowEndMicros]
                !== [$aggregate->componentRef, $aggregate->metricRef, $aggregate->metricVersion,
                    $aggregate->windowStartMicros, $aggregate->windowEndMicros]
                || CanonicalJson::encode($oldWindow) !== CanonicalJson::encode($window)
                || CanonicalJson::encode($oldAggregate->quantitySnapshot) !== CanonicalJson::encode($aggregate->quantitySnapshot)
                || (FixedOccurrenceInput::matches($oldAggregate) && CanonicalJson::encode($oldAggregate) !== CanonicalJson::encode($aggregate))) {
                RatingComputation::refuse('correction_digest_mismatch');
            }
        }
        $expectedProration = $old->proration;
        $amendment = $p->command->amendment;
        if (($p->command->kind === CorrectionKind::PartialCancel) !== ($amendment !== null)
            || ($amendment !== null && ($amendment->amendmentRef === '' || $amendment->revision < 1))) {
            RatingComputation::refuse('amendment_unsupported');
        }
        if ($amendment !== null) {
            $this->amendmentSupport->assertRevision(previous: $old->proration, amendment: $amendment);
            foreach ($old->prices as $i => $price) {
                $this->amendmentSupport->assertSupported(kind: $amendment->kind, price: $price->snapshot,
                    quantitySnapshot: $old->aggregates[$i]->quantitySnapshot);
            }
            $fraction = $this->proration->fraction(policy: $amendment->prorationPolicy, start: Micros::instant($in->periodStartMicros),
                end: Micros::instant($in->periodEndMicros), effectiveAt: $amendment->effectiveAt, timezone: $amendment->timezone);
            $expectedProration = [...$fraction, 'amendmentRef' => $amendment->amendmentRef, 'revision' => $amendment->revision,
                'effectiveAt' => $amendment->effectiveAt->format('Y-m-d\TH:i:s.uP'), 'policy' => $amendment->prorationPolicy->value,
                'timezone' => $amendment->timezone];
        }
        if (CanonicalJson::encode($expectedProration) !== CanonicalJson::encode($in->proration)) {
            RatingComputation::refuse('correction_digest_mismatch');
        }
        $taxes = array_map(static fn (FrozenTax $tax): TaxSnapshot => $tax->snapshot, $originalInputs->taxes);
        $tax = $taxes[0];
        if (! TaxCorrectionGroup::isHomogeneous($taxes)) {
            RatingComputation::refuse('tax_policy_unsupported');
        }
        $preliminary = CorrectionInputs::replace(old: $old, aggregates: $in->aggregates, taxes: $taxes,
            sourceDigest: CanonicalJson::digest($digests), version: $p->expectedFrontierRowVersion,
            frontierDigest: $p->expectedFrontierDigest, proration: $in->proration);
        $candidate = $this->rating->compute(inputs: $preliminary, proration: $in->proration,
            predecessorBasisRef: $p->computation->predecessorBasisRef, correctionRevision: $p->computation->correctionRevision);
        $inputDigest = CanonicalJson::digest([$in->sellerRef, $in->environment, $in->occurrenceRef,
            $tax, $request->correctionEffectiveDate, $p->command->reasonCode, $originalDraft->netMinor, $candidate->netMinor, $digests]);
        $effective = $p->command->correctionEffectiveDate ?? $amendment?->effectiveAt;
        if ($effective === null || ($effective <=> $request->correctionEffectiveDate) !== 0 || $request->sellerRef !== $in->sellerRef
            || $request->environment !== $in->environment || $request->parentOccurrenceRef !== $in->occurrenceRef
            || $request->reasonCode !== $p->command->reasonCode || $request->jurisdiction !== $tax->jurisdiction
            || $request->originalTaxSnapshotRef !== $tax->snapshotRef || $request->originalTaxSnapshotDigest !== CanonicalJson::digest($tax)
            || ($request->originalTaxPoint <=> $tax->taxPoint) !== 0 || $request->originalTaxableBaseMinor !== $originalDraft->netMinor
            || $request->correctedTaxableBaseMinor !== $candidate->netMinor
            || $request->correctedTaxableBaseMinor !== $p->computation->correctedBasis->netMinor || $request->inputDigest !== $inputDigest
            || $policy->selectedRate->inputDigest !== $inputDigest
            || ! in_array($policy->treatment, ['original_rate', 'current_legal_rate', 'other_supported'], true)
            || $policy->selectedRate->kind !== TaxSnapshotKind::Correction
            || $policy->selectedRate->jurisdiction !== $tax->jurisdiction || $policy->selectedRate->taxCode !== $tax->taxCode) {
            RatingComputation::refuse('tax_policy_stale');
        }
        foreach ($in->taxes as $frozenTax) {
            if (CanonicalJson::encode($frozenTax->snapshot) !== CanonicalJson::encode($policy->selectedRate)) {
                RatingComputation::refuse('tax_policy_stale');
            }
        }
        if ($head['version'] !== $p->expectedPolicyRowVersion || $head['digest'] !== TaxPolicyFrontier::digest($policy)
            || $request->inputDigest !== $policy->inputDigest || $request->correctionEffectiveDate < $policy->effectiveFrom
            || ($policy->effectiveTo !== null && $request->correctionEffectiveDate > $policy->effectiveTo)
            || $expectedFrontier !== $p->computation->sourceFrontierDigest || $expectedFrontier !== $in->sourceDigest
            || CanonicalJson::encode($in->correctionEvidence) !== CanonicalJson::encode(['request' => $request, 'policy' => $policy])) {
            RatingComputation::refuse('tax_policy_stale');
        }
    }
}
