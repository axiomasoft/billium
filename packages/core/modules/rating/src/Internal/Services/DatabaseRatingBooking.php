<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Services;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Data\Rating\BookingReceipt;
use Billium\Rating\Data\Rating\BookRatedBasis;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Enums\BookingOutcome;
use Billium\Rating\Enums\ChargeKind;
use Billium\Rating\Internal\Adapters\LedgerAdapter;
use Billium\Rating\Internal\Adapters\ScheduleOccurrenceAdapter;
use Billium\Rating\Internal\Adapters\UsageAggregateAdapter;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\FixedOccurrenceInput;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Rating\Internal\Storage\RatingStore;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseRatingBooking implements RatingBooking
{
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private RatingStore $store,
        private RatingComputation $computation, private UsageAggregateAdapter $usage, private ScheduleOccurrenceAdapter $schedule,
        private LedgerAdapter $ledger, private BilliumOptions $options) {}

    public function book(BookRatedBasis $command): BookingReceipt
    {
        $in = $command->prepared->inputs;
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $in->sellerRef || $caller->environment !== $in->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.rating.scope_denied');
        }
        $modules = ['platform', 'rating', 'schedule'];
        if (array_any($in->aggregates, static fn (FrozenAggregate $aggregate): bool => ! FixedOccurrenceInput::matches($aggregate))) {
            $modules[] = 'usage';
        }
        $enabled = $this->options->modules->enabled;
        if (in_array('ledger', $enabled, true)) {
            $modules[] = 'ledger';
        }
        try {
            return $this->runRoot(command: $command, modules: $modules);
        } catch (ModuleAdmissionRefused $error) {
            $disabled = array_map(static fn (string $state): string => $state.'_closes_'.ModuleOperationClass::NewIntent->value,
                ['draining', 'recovery_only', 'retired']);
            if ($error->moduleId !== 'ledger' || ! in_array($error->reason, [...$disabled, 'module_not_installed', 'retired'], true)) {
                throw $error;
            }

            // Admission failed before the body and rolled back the claim. There was no commit or effect.
            // The only successful root now freezes an unposted basis; it cannot call a ledger participant.
            return $this->runRoot(command: $command, modules: array_values(array_diff($modules, ['ledger'])));
        }
    }

    /** @param list<string> $modules */
    private function runRoot(BookRatedBasis $command, array $modules): BookingReceipt
    {
        $in = $command->prepared->inputs;
        $draft = $command->prepared->draft;
        $outcome = $this->runner->run(spec: new OperationSpec(commandKind: 'rating.book.v1', sellerRef: $in->sellerRef, environment: $in->environment,
            mode: FinancialMode::Active, operationClass: ModuleOperationClass::NewIntent, modules: $modules,
            commandKey: $command->operationKey, digest: $in->digest(), commitKind: 'rating.book.v1'),
            body: function (TransactionContext $context) use ($command, $in, $draft): BookingReceipt {
                // This must precede every adapter, lock-head insert and write (D60).
                $this->runner->assertCurrent(context: $context, moduleId: 'rating');
                if (CanonicalJson::encode($this->computation->compute($in)) !== CanonicalJson::encode($draft)) {
                    RatingComputation::refuse('digest_mismatch');
                }
                // Complete rank-35 set is acquired before rank-45 and rank-50, including all retry attempts.
                $this->usage->lock(context: $context, inputs: $in);
                $this->store->lockFrontiers(context: $context, inputs: $in);
                $existing = $this->store->basis(ref: $draft->basisRef, context: $context);
                $ledgerOn = array_key_exists('ledger', $context->admittedModules());
                $booking = $existing === null ? null : $this->store->booking(id: RatingRows::integer($existing->id), context: $context);
                if ($existing !== null && ($booking !== null || ! $ledgerOn)) {
                    return new BookingReceipt(basisRef: $draft->basisRef, outcome: BookingOutcome::Existing,
                        obligationRef: RatingRows::nullableText($booking?->obligation_ref), grossMinor: $draft->grossMinor, currency: $in->currency);
                }
                if ($existing === null) {
                    $this->store->assertFrontier(context: $context, inputs: $in);
                    $this->store->assertCommitments(context: $context, inputs: $in);
                    $this->usage->assertCurrent(context: $context, inputs: $in);
                } else {
                    // Unposted basis uses its retained inputs; a changed commitment cannot silently change its amount.
                    $this->store->assertCommitments(context: $context, inputs: $in);
                    $this->usage->assertCurrent(context: $context, inputs: $in);
                }
                $this->schedule->lock(context: $context, ref: $in->occurrenceRef);
                $this->schedule->assertBookable($in);
                $id = $existing === null ? $this->store->insertBasis(context: $context, prepared: $command->prepared) : RatingRows::integer($existing->id);
                $obligation = null;
                if ($draft->chargeKind === ChargeKind::NoCharge) {
                    $this->store->insertBooking(context: $context, id: $id, obligation: null, basis: $draft->basisRef);
                    $kind = BookingOutcome::NoCharge;
                } elseif ($ledgerOn) {
                    // A new obligation has no existing row to lock. The occurrence/frontier serializes creation;
                    // ledger's seller-scoped basis UNIQUE is its final arbiter, through the actual D60 participant.
                    $obligation = $this->ledger->record(context: $context, prepared: $command->prepared);
                    $this->store->insertBooking(context: $context, id: $id, obligation: $obligation, basis: $draft->basisRef);
                    $kind = BookingOutcome::Booked;
                } else {
                    $kind = BookingOutcome::Unposted;
                }
                $this->schedule->advance(context: $context, inputs: $in, basis: $draft->basisRef, booked: $obligation !== null);
                $this->store->advanceFrontier(context: $context, inputs: $in, draft: $draft, alreadyFrozen: $existing !== null);
                $receipt = new BookingReceipt(basisRef: $draft->basisRef, outcome: $kind, obligationRef: $obligation,
                    grossMinor: $draft->grossMinor, currency: $in->currency);
                $this->store->event(context: $context, id: $id,
                    kind: $obligation === null ? 'rating.basis.frozen.v1' : 'rating.basis.booked.v1', receipt: $receipt);

                return $receipt;
            })->orThrow();

        return BookingReceipt::fromBody($outcome->body);
    }
}
