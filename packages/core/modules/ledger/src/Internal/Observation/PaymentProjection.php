<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Observation;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Ledger\Internal\Commands\AllocateLockPlan;
use Billium\Ledger\Internal\InvoiceStatus;
use Billium\Ledger\Internal\Policy\ProRataOpenPolicy;
use Billium\Ledger\Internal\Receipts\AllocationReceiptWriter;
use Billium\Ledger\Internal\Repositories\LedgerRepository;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\HandlerOutcomeKind;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Economic apply of ONE observed revision inside its root Tx (observation-apply, D41, D46, D57, D60).
 *
 * 1. The observed fact is recorded first and survives every later branch: actual values stay exact and unbounded.
 * 2. A charge resolves its billing account through the authenticated settlement intent (or the payment it already
 *    created); without one the allocation parks (allocation_pending) and the observed capture stays queryable.
 * 3. The complete plan intent (65) → targets (70) → invoices (80) → payment (90) is locked before any guarded routine
 *    and re-read; a changed plan retries the DB-only work.
 * 4. Ordinary consistent snapshot: assigned totals (charge refundedTotal is the only ordinary refund basis) and
 *    pro_rata_open/1 parts of the available normal funds. Exceptional actual (over-refund, over-capture, regression,
 *    a source discrepancy, an effect on a closed intent): financial hold and discrepancy, last consistent applied
 *    totals kept, never clamped. A compatible older revision moves no money.
 * 5. A refund child only records its own detail linked to the original charge; its absence parks the linkage.
 *
 * Receipt, journal, counters and outbox commit with the inbox disposition (PF-12). No PSP call happens here.
 */
final readonly class PaymentProjection
{
    public const string MODE_ASSIGN = 'assign';

    public const string MODE_HOLD = 'hold';

    public const string MODE_STALE = 'stale';

    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private LedgerRepository $routines,
        private AllocationReceiptWriter $writer,
        private ProRataOpenPolicy $policy,
    ) {}

    public function apply(TransactionContext $context, PaymentObservation $observation, ?int $inboxId = null): HandlerOutcome
    {
        $this->runner->assertCurrent($context, 'ledger');
        $caller = $context->caller();
        $refusal = self::refusal($caller, $observation);
        if ($refusal !== null) {
            return HandlerOutcome::conflict($refusal->value);
        }
        $db = $this->databases->connection($context->connectionName());
        $material = EconomicDigest::of($observation);
        try {
            $recorded = $this->routines->call($db, 'record_observation', [
                $caller->installationRef, $caller->sellerRef, $caller->environment, $observation->sourceAuthority, $observation->sourceLineage,
                $observation->operationRef, $observation->operationKind, $observation->originalPaymentRef, $observation->operationRevision, $material,
                self::state($observation->state), $observation->currency, $observation->requested->minor, $observation->authorizedTotal->minor,
                $observation->capturedTotal->minor, $observation->refundedTotal->minor, $observation->providerAccountRef, $observation->evidenceRef,
                json_encode($observation->raw, JSON_THROW_ON_ERROR),
            ], 'ledger.observed_payment')->body();
            // Same revision with another economic digest, or another immutable binding: quarantine, the original stays.
            $outcome = in_array($recorded['disposition'] ?? null, ['conflict', 'contradiction'], true)
                ? HandlerOutcome::conflict(ProblemCode::ObservationConflict->value)
                : ($observation->operationKind === 'refund'
                    ? $this->refund($db, $caller, $observation, self::int($recorded['observed_id'] ?? null), $material, $inboxId)
                    : $this->charge($context, $db, $caller, $observation, self::int($recorded['observed_id'] ?? null), $material, $inboxId));
        } catch (ProblemException $problem) {
            $outcome = HandlerOutcome::conflict($problem->problem->value);
        }
        if ($inboxId !== null && $outcome->kind !== HandlerOutcomeKind::ParkedDependency) {
            $this->release($db, $caller, null, $inboxId, $outcome->kind->value);
        }

        return $outcome;
    }

    /** Releases a parked observation decided by a retry (applied, quarantined or superseded). */
    public function release(Connection $db, Caller $caller, ?int $parkId, ?int $inboxId, string $reason): void
    {
        $open = $db->table('billium_observation_park')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
            ->whereNull('released_at')->where(static function (Builder $query) use ($parkId, $inboxId): void {
                $query->where('id', $parkId ?? 0)->orWhere('inbox_id', $inboxId ?? 0);
            })->exists();
        if ($open) {
            $this->routines->call($db, 'release_observation_park', [$caller->sellerRef, $caller->environment, $parkId, $inboxId, $reason], 'ledger.observation_park');
        }
    }

    public static function receiptRef(string $prefix, string $authority, string $operationRef, int $revision): string
    {
        $identity = rawurlencode($authority).':'.rawurlencode($operationRef);
        $ref = $prefix.':'.$identity.'#'.$revision;

        return strlen($ref) <= 200 ? $ref : $prefix.':sha256:'.hash('sha256', $identity).'#'.$revision;
    }

    private function charge(TransactionContext $context, Connection $db, Caller $caller, PaymentObservation $o, int $observedId, string $material, ?int $inboxId): HandlerOutcome
    {
        $read = static function () use ($db, $caller, $o): array {
            $intent = $db->table('billium_settlement_intent')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
                ->where('business_intent_ref', $o->businessIntentRef)->first(['id', 'billing_account_ref', 'currency', 'status']);
            $payment = $db->table('billium_payment')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
                ->where('source_authority', $o->sourceAuthority)->where('payment_ref', $o->operationRef)->first(['id', 'billing_account_ref', 'currency', 'settlement_intent_id', 'kind']);
            $targets = [];
            if ($intent !== null) {
                foreach ($db->table('billium_settlement_target as t')->join('billium_obligation as o', 'o.id', '=', 't.obligation_id')
                    ->where('t.seller_ref', $caller->sellerRef)->where('t.environment', $caller->environment)->where('t.settlement_intent_id', $intent->id)
                    ->orderBy('t.position')->get(['o.id', 'o.billed_invoice_id']) as $target) {
                    $targets[] = ['id' => self::int($target->id), 'invoice' => $target->billed_invoice_id === null ? null : self::int($target->billed_invoice_id)];
                }
            }

            return [
                'intent' => $intent === null ? null : ['id' => self::int($intent->id), 'account' => self::text($intent->billing_account_ref),
                    'currency' => self::text($intent->currency), 'status' => self::text($intent->status)],
                'payment' => $payment === null ? null : ['id' => self::int($payment->id), 'account' => self::text($payment->billing_account_ref),
                    'currency' => self::text($payment->currency), 'kind' => self::text($payment->kind),
                    'intent' => $payment->settlement_intent_id === null ? null : self::int($payment->settlement_intent_id)],
                'targets' => $targets,
            ];
        };
        $before = $read();
        $intent = $before['intent'];
        $payment = $before['payment'];
        $closed = $intent !== null && $intent['status'] === 'closed_no_effect';
        if ($payment !== null) {
            if ($payment['kind'] !== 'charge' || $payment['currency'] !== $o->currency) {
                return HandlerOutcome::conflict(ProblemCode::ObservationConflict->value);
            }
            [$account, $intentId] = [$payment['account'], $payment['intent']];
        } elseif ($intent !== null) {
            if ($intent['currency'] !== $o->currency) {
                return HandlerOutcome::conflict(ProblemCode::MoneyCurrencyMismatch->value);
            }
            // An effect on a closed intent never silently reopens it: the payment keeps no intent and is held below.
            [$account, $intentId] = [$intent['account'], $closed ? null : $intent['id']];
        } else {
            // No authenticated account mapping yet: no allocatable source, the observed fact stays visible (D60).
            return $this->park($db, $caller, $o, $observedId, $inboxId, 'settlement_intent', $o->businessIntentRef);
        }
        $targets = $intent !== null && $intentId === $intent['id'] ? $before['targets'] : [];
        $plan = new AllocateLockPlan(
            [$intent['id'] ?? null],
            array_column($targets, 'id'),
            array_column($targets, 'invoice'),
            [$payment['id'] ?? null],
        );
        $plan->lock($context);
        if ($read() !== $before) {
            throw new LockPlanChanged('observation plan changed');
        }

        $applied = $payment === null ? null : $this->payment($db, $payment['id'], $observedId);
        $revision = $o->operationRevision;
        $binding = $payment !== null && $payment['intent'] !== ($intent === null || $closed ? null : $intent['id']);
        // An unseen older fact can still contradict the consistent history; only compatible history is stale.
        $exceptional = self::exceptional($db, $observedId, $o, $applied, $closed, $binding);
        if ($exceptional === null) {
            $earlierPark = $db->table('billium_observation_park')->where('observed_payment_id', $observedId)
                ->whereNull('released_at')->where('revision', '<', $revision)->orderBy('revision')->first(['revision']);
            if ($earlierPark !== null) {
                return $this->park($db, $caller, $o, $observedId, $inboxId, 'operation_revision', (string) self::int($earlierPark->revision));
            }
        }
        if ($exceptional !== null) {
            $mode = self::MODE_HOLD;
        } elseif ($applied !== null && $revision <= $applied['revision']) {
            $mode = self::MODE_STALE;
        } else {
            $mode = ($applied['hold'] ?? false) ? self::MODE_HOLD : self::MODE_ASSIGN;
        }
        $parts = $mode === self::MODE_ASSIGN ? $this->parts($db, $o, $applied, $targets) : [];
        $receiptRef = self::receiptRef('observation', $o->sourceAuthority, $o->operationRef, $revision);
        [$receipt, $invoices] = $this->writer->apply($context, $db, 'project_payment', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $account, $o->currency, $observedId, $revision, $o->operationRef,
            $intentId, $mode, json_encode($parts, JSON_THROW_ON_ERROR),
            $exceptional === null ? null : json_encode($exceptional, JSON_THROW_ON_ERROR), $receiptRef, $material,
        ], 'ledger.payment_observation', $plan->invoices);
        if (! $receipt->replayed) {
            $this->events($context, $db, $o, $receipt->body(), $parts, $targets, $invoices, $account);
        }

        return HandlerOutcome::applied(self::text($receipt->body()['receipt_ref'] ?? null));
    }

    private function refund(Connection $db, Caller $caller, PaymentObservation $o, int $observedId, string $material, ?int $inboxId): HandlerOutcome
    {
        $original = $o->originalPaymentRef ?? throw new LogicException('A refund names its original charge');
        $payment = $db->table('billium_payment')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
            ->where('source_authority', $o->sourceAuthority)->where('payment_ref', $original)->where('kind', 'charge')->value('id');
        if ($payment === null) {
            // Refund detail before its charge: observed and parked on the linkage, no ledger effect (REQ-FAIL-12).
            return $this->park($db, $caller, $o, $observedId, $inboxId, 'original_charge', $original);
        }
        $receiptRef = self::receiptRef('refund-detail', $o->sourceAuthority, $o->operationRef, $o->operationRevision);
        // D41: the child never touches payment.refunded_minor; RefundReconciler compares it after the window (D48).
        $receipt = $this->routines->call($db, 'record_refund_detail', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $observedId, $o->operationRevision, self::int($payment), $receiptRef, $material,
        ], 'ledger.refund_detail');

        return HandlerOutcome::applied(self::text($receipt->body()['receipt_ref'] ?? null));
    }

    private function park(Connection $db, Caller $caller, PaymentObservation $o, int $observedId, ?int $inboxId, string $kind, string $ref): HandlerOutcome
    {
        $this->routines->call($db, 'park_observation', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $observedId, $o->operationRevision, $inboxId, $kind, $ref,
        ], 'ledger.observation_park');

        return HandlerOutcome::parkedDependency($kind, substr($ref, 0, 200));
    }

    /**
     * @param  array{revision: int, state: string|null, captured: int, refunded: int, allocated: int, hold: bool}|null  $applied
     * @param  list<array{id: int, invoice: int|null}>  $targets
     * @return list<array{obligation_id: int, amount_minor: string}>
     */
    private function parts(Connection $db, PaymentObservation $o, ?array $applied, array $targets): array
    {
        $available = $o->capturedTotal->minor - $o->refundedTotal->minor - ($applied['allocated'] ?? 0);
        if ($available <= 0 || $targets === []) {
            return []; // nothing new, or a confirmed refund made the payment over-allocated (reallocation_required, D35)
        }
        $open = [];
        foreach ($targets as $target) {
            $row = $db->table('billium_obligation')->where('id', $target['id'])->first(['open_minor', 'status']);
            $open[] = $row === null || $row->status === 'cancelled' ? 0 : max(0, self::int($row->open_minor));
        }
        $shares = $this->policy->allocate(new Money($available, Currency::of($o->currency)), $open);
        $parts = [];
        foreach ($targets as $index => $target) {
            if ($shares[$index]->minor > 0) {
                $parts[] = ['obligation_id' => $target['id'], 'amount_minor' => (string) $shares[$index]->minor];
            }
        }

        return $parts; // the overpayment beyond the open amounts stays unallocated on the payment (REQ-OWN-12)
    }

    /** @return array{revision: int, state: string|null, captured: int, refunded: int, allocated: int, hold: bool} */
    private function payment(Connection $db, int $id, int $observedId): array
    {
        $row = $db->table('billium_payment')->where('id', $id)->first(['applied_revision', 'captured_minor', 'refunded_minor', 'allocated_minor', 'financial_hold'])
            ?? throw new LockPlanChanged('payment vanished');
        $revision = self::int($row->applied_revision);
        // The latest observed state may already contradict the projection: use its immutable applied revision.
        $state = $revision === 0 ? null : self::text($db->table('billium_observed_payment_revision')
            ->where('observed_payment_id', $observedId)->where('revision', $revision)->value('state'));

        return ['revision' => $revision, 'state' => $state, 'captured' => self::int($row->captured_minor), 'refunded' => self::int($row->refunded_minor),
            'allocated' => self::int($row->allocated_minor), 'hold' => $row->financial_hold === true];
    }

    /**
     * Actual provider facts outside the normal bounds of the applied projection (D57): retained, held, never clamped.
     *
     * @param  array{revision: int, state: string|null, captured: int, refunded: int, allocated: int, hold: bool}|null  $applied
     * @return array{fingerprint: string, reason: string, expected_minor: string, actual_minor: string, exposure_minor: string}|null
     */
    private static function exceptional(Connection $db, int $observedId, PaymentObservation $o, ?array $applied, bool $closed, bool $binding): ?array
    {
        $captured = $o->capturedTotal->minor;
        $refunded = $o->refundedTotal->minor;
        $older = $applied !== null && $o->operationRevision < $applied['revision'];
        // Compare in revision order: lower cumulative totals and a pre-terminal state are valid older history.
        $previousState = $older ? self::state($o->state) : ($applied['state'] ?? null);
        $nextState = $older ? $applied['state'] : self::state($o->state);
        $at = self::receiptRef('source', $o->sourceAuthority, $o->operationRef, $o->operationRevision);
        [$reason, $expected, $actual] = match (true) {
            $closed && ($captured > 0 || $refunded > 0) => ['closed_intent_effect', 0, $captured],
            $binding => ['binding_changed', $applied['captured'] ?? 0, $captured],
            $o->discrepancy !== null => ['source_reported', $applied['captured'] ?? 0, $captured],
            $refunded > $captured => ['over_refund', $captured, $refunded],
            $captured > $o->authorizedTotal->minor => ['over_capture', $o->authorizedTotal->minor, $captured],
            $applied !== null && ($older ? $captured > $applied['captured'] : $captured < $applied['captured']) => ['regression_captured', $applied['captured'], $captured],
            $applied !== null && ($older ? $refunded > $applied['refunded'] : $refunded < $applied['refunded']) => ['regression_refunded', $applied['refunded'], $refunded],
            $applied !== null && in_array($previousState, ['succeeded', 'failed', 'cancelled'], true)
                && $nextState !== $previousState => ['regression_state', $applied['captured'], $captured],
            default => [null, 0, 0],
        };
        if ($reason === null) {
            // Parked and unapplied facts constrain every candidate, even beyond its nearest neighbors.
            $history = $db->table('billium_observed_payment_revision')->where('observed_payment_id', $observedId)
                ->orderBy('revision')->get(['revision', 'state', 'captured_minor', 'refunded_minor']);
            foreach ($history as $neighbor) {
                if (self::int($neighbor->revision) === $o->operationRevision) {
                    continue;
                }
                $predecessor = self::int($neighbor->revision) < $o->operationRevision;
                $neighborCaptured = self::int($neighbor->captured_minor);
                $neighborRefunded = self::int($neighbor->refunded_minor);
                $previousState = $predecessor ? self::text($neighbor->state) : self::state($o->state);
                $nextState = $predecessor ? self::state($o->state) : self::text($neighbor->state);
                [$reason, $expected, $actual] = match (true) {
                    $predecessor ? $captured < $neighborCaptured : $captured > $neighborCaptured => ['regression_captured', $neighborCaptured, $captured],
                    $predecessor ? $refunded < $neighborRefunded : $refunded > $neighborRefunded => ['regression_refunded', $neighborRefunded, $refunded],
                    in_array($previousState, ['succeeded', 'failed', 'cancelled'], true)
                        && $nextState !== $previousState => ['regression_state', $neighborCaptured, $captured],
                    default => [null, 0, 0],
                };
                if ($reason !== null) {
                    break;
                }
            }
            // Monotonic totals and terminal-state preservation are transitive: checking each ordered
            // pair of consecutive persisted facts detects a contradiction between any historical pair.
            $previous = null;
            foreach ($history as $row) {
                if ($reason !== null) {
                    break;
                }
                if ($previous !== null) {
                    $previousCaptured = self::int($previous->captured_minor);
                    $previousRefunded = self::int($previous->refunded_minor);
                    $rowCaptured = self::int($row->captured_minor);
                    $rowRefunded = self::int($row->refunded_minor);
                    [$reason, $expected, $actual] = match (true) {
                        $rowCaptured < $previousCaptured => ['regression_captured', $previousCaptured, $rowCaptured],
                        $rowRefunded < $previousRefunded => ['regression_refunded', $previousRefunded, $rowRefunded],
                        in_array(self::text($previous->state), ['succeeded', 'failed', 'cancelled'], true)
                            && $row->state !== $previous->state => ['regression_state', $previousCaptured, $rowCaptured],
                        default => [null, 0, 0],
                    };
                }
                $previous = $row;
            }
        }
        if ($reason === null) {
            return null;
        }
        $fingerprint = $reason === 'source_reported'
            ? 'source_reported:'.rawurlencode($o->sourceAuthority).':'.rawurlencode($o->operationRef).':'.self::scalar($o->discrepancy?->discrepancyRef).'#'.self::scalar($o->discrepancy?->revision)
            : 'held:'.$reason.':'.$at;

        return ['fingerprint' => $fingerprint, 'reason' => $reason, 'expected_minor' => (string) $expected, 'actual_minor' => (string) $actual,
            'exposure_minor' => (string) abs($actual - $expected)];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<array{obligation_id: int, amount_minor: string}>  $parts
     * @param  list<array{id: int, invoice: int|null}>  $targets
     * @param  array<int, array{ref: string, status: InvoiceStatus, total: int, settled: int, revision: int}>  $invoices
     */
    private function events(TransactionContext $context, Connection $db, PaymentObservation $o, array $body, array $parts, array $targets, array $invoices, string $account): void
    {
        if (($body['mode'] ?? null) !== self::MODE_STALE) {
            $this->writer->event($context, 'payment.outcome.v1', $o->operationRef, $o->operationRevision, $body);
        }
        $invoiceOf = array_column($targets, 'invoice', 'id');
        foreach ($parts as $part) {
            $obligation = $db->table('billium_obligation')->where('id', $part['obligation_id'])->first(['obligation_ref', 'revision'])
                ?? throw new LogicException('Allocated obligation vanished');
            $invoice = ($invoiceOf[$part['obligation_id']] ?? null) === null ? null : ($invoices[$invoiceOf[$part['obligation_id']]] ?? null);
            $this->writer->event($context, 'allocation.applied.v1', self::text($obligation->obligation_ref), self::int($obligation->revision), [
                'receipt_ref' => self::text($body['receipt_ref'] ?? null),
                'obligation_ref' => self::text($obligation->obligation_ref),
                'billing_account_ref' => $account,
                'source_kind' => 'payment',
                'source_ref' => $o->operationRef,
                'amount_minor' => $part['amount_minor'],
                'currency' => $o->currency,
                'policy_version' => ProRataOpenPolicy::VERSION,
                'invoice_ref' => $invoice['ref'] ?? null,
                'invoice_status' => $invoice === null ? null : $invoice['status']->value,
            ]);
        }
        $discrepancy = $body['discrepancy'] ?? null;
        if (is_array($discrepancy) && ($discrepancy['recorded'] ?? false) === true) {
            $this->writer->event($context, 'discrepancy.recorded.v1', $o->operationRef, $o->operationRevision, [
                'fingerprint' => $discrepancy['fingerprint'] ?? null,
                'reason' => $discrepancy['reason'] ?? null,
                'payment_ref' => $o->operationRef,
                'billing_account_ref' => $account,
                'currency' => $o->currency,
                'observed_revision' => $o->operationRevision,
                'expected_minor' => $discrepancy['expected_minor'] ?? null,
                'actual_minor' => $discrepancy['actual_minor'] ?? null,
                'exposure_minor' => $discrepancy['exposure_minor'] ?? null,
                'financial_hold' => true,
                'receipt_ref' => $body['receipt_ref'] ?? null,
            ]);
        }
    }

    /** Scope and internal consistency the schema cannot express (INV-14, rule 6); a refusal quarantines. */
    private static function refusal(Caller $caller, PaymentObservation $o): ?ProblemCode
    {
        if ($o->sellerRef !== $caller->sellerRef || $o->environment !== $caller->environment || $o->installationRef !== $caller->installationRef
            || ! in_array($o->sourceAuthority, $caller->sourceAuthorities, true)) {
            return ProblemCode::AuthDenied;
        }
        foreach ([$o->requested, $o->authorizedTotal, $o->capturedTotal, $o->refundedTotal] as $money) {
            if ($money->currency->code !== $o->currency) {
                return ProblemCode::ObservationConflict;
            }
        }
        if (($o->operationKind === 'refund') !== ($o->originalPaymentRef !== null)) {
            return ProblemCode::ObservationConflict;
        }

        return null;
    }

    private static function state(string $wire): string
    {
        return match ($wire) {
            'accepted-unknown' => 'unknown',
            'failed-terminal' => 'failed',
            default => $wire,
        };
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected a PostgreSQL bigint');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a text value');
    }
}
