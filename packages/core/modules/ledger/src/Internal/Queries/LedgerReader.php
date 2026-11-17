<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Ledger\Data\Views\LedgerView as View;
use Billium\Ledger\Internal\Observation\ParkedDrainer;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** D60: shared query/snapshot projection over ledger-owned facts, scoped before resolving any selector (INV-14). */
#[Internal]
final readonly class LedgerReader
{
    public function __construct(private Connection $db, private Caller $caller, private FeedRecoveryQueries $feedRecovery) {}

    public function scoped(string $table): Builder
    {
        return $this->db->table($table)->where('installation_ref', $this->caller->installationRef)
            ->where('seller_ref', $this->caller->sellerRef)->where('environment', $this->caller->environment);
    }

    public function one(string $table, string $column, string $ref): stdClass
    {
        return $this->scoped($table)->where($column, $ref)->first() ?? throw self::unknown();
    }

    /** @return array<string, mixed> */
    public function obligation(string $ref): array
    {
        $row = $this->one('billium_obligation', 'obligation_ref', $ref);
        $invoice = $row->billed_invoice_id === null ? null : $this->scoped('billium_invoice')->where('id', $row->billed_invoice_id)->first();
        if ($row->billed_invoice_id !== null && $invoice === null) {
            throw self::unknown();
        }

        return [...View::obligation($row), 'billedInvoiceRef' => $invoice?->invoice_ref, 'billedInvoiceStatus' => $invoice?->status];
    }

    /** @return array<string, mixed> */
    public function invoice(string $ref): array
    {
        $row = $this->one('billium_invoice', 'invoice_ref', $ref);
        $lines = [];
        foreach ($this->scoped('billium_invoice_line')->where('invoice_id', $row->id)->orderBy('position')->get() as $line) {
            $obligation = $this->scoped('billium_obligation')->where('id', $line->obligation_id)->first() ?? throw self::unknown();
            $lines[] = ['obligation' => View::obligation($obligation), 'position' => $line->position,
                'subtotal' => View::money($line->subtotal_minor, $line->currency), 'tax' => View::money($line->tax_minor, $line->currency),
                'total' => View::money($line->amount_minor, $line->currency), 'snapshot' => (object) View::json($line->snapshot)];
        }

        return ['invoiceRef' => $row->invoice_ref, 'billingAccountRef' => $row->billing_account_ref, 'customerRef' => $row->customer_ref,
            'status' => $row->status, 'revision' => $row->revision, 'snapshot' => (object) View::json($row->snapshot),
            'metadata' => (object) View::json($row->metadata), 'legalDocumentRef' => $row->legal_document_ref, 'legalNumber' => null,
            'issuedAt' => View::timestamp($row->issued_at), 'subtotal' => View::money($row->subtotal_minor, $row->currency),
            'tax' => View::money($row->tax_minor, $row->currency), 'total' => View::money($row->total_minor, $row->currency),
            'allocated' => View::money($row->allocated_minor, $row->currency), 'credited' => View::money($row->credited_minor, $row->currency),
            'open' => View::money($row->open_minor, $row->currency), 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    public function payment(string $ref, ?string $authority = null): array
    {
        $query = $this->scoped('billium_observed_payment')->where('operation_ref', $ref)->where('kind', 'charge');
        if ($authority !== null) {
            $query->where('source_authority', $authority);
        }
        $observations = $query->orderBy('id')->limit(2)->get();
        if ($observations->count() > 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Ambiguous payment reference; specify sourceAuthority');
        }
        $observed = $observations->first() ?? throw self::unknown();
        $payment = $this->scoped('billium_payment')->where('source_authority', $observed->source_authority)
            ->where('operation_ref', $observed->operation_ref)->where('kind', 'charge')->first();
        $money = static fn (mixed $amount): array => View::money($amount, $observed->currency);
        $revisions = $this->scoped('billium_observed_payment_revision')->where('observed_payment_id', $observed->id)->orderBy('revision')->get();
        $evidence = [];
        $current = null;
        $appliedState = null;
        foreach ($revisions as $revision) {
            $evidence[] = View::text($revision->evidence_ref);
            if ($revision->revision === $observed->revision) {
                $current = $revision;
            }
            if ($payment !== null && $revision->revision === $payment->applied_revision) {
                $appliedState = $revision->state;
            }
        }
        $payload = View::json($current->payload ?? '{}');
        $discrepancies = $this->scoped('billium_ledger_discrepancy')->where(function (Builder $q) use ($observed, $ref): void {
            $q->where('observed_payment_id', $observed->id)->orWhereIn('observed_payment_id',
                $this->scoped('billium_observed_payment')->select('id')->where('source_authority', $observed->source_authority)
                    ->where('kind', 'refund')->where('original_ref', $ref));
        })
            ->whereNotExists(function (Builder $q): void {
                $q->selectRaw('1')->from('billium_discrepancy_event as e')->whereColumn('e.discrepancy_id', 'billium_ledger_discrepancy.id')->where('e.kind', 'resolved');
            })->orderBy('id')->get();
        $reasons = [];
        $refs = [];
        $held = ($payment->financial_hold ?? false) === true;
        foreach ($discrepancies as $discrepancy) {
            if ($discrepancy->hold === true) {
                $held = true;
                $reasons[] = View::text($discrepancy->reason);
                $refs[] = View::text($discrepancy->fingerprint);
            }
        }
        $park = $this->scoped('billium_observation_park')->where('observed_payment_id', $observed->id)->whereNull('released_at')->orderByDesc('id')->first();
        $connectionName = $this->db->getName() ?? throw new LogicException('Query connection must be named');
        $inboxPark = $park !== null ? null : $this->feedRecovery->pendingOperationPark($connectionName,
            ParkedDrainer::CHANNEL, View::text($observed->source_authority), View::text($observed->operation_ref));
        $pendingPark = $park !== null || $inboxPark !== null;
        $partitionRef = is_string($payload['partitionRef'] ?? null) ? $payload['partitionRef'] : null;
        $recovery = $this->feedRecovery->state($connectionName,
            ParkedDrainer::CHANNEL, View::text($observed->source_authority), $partitionRef);
        $status = $recovery['recoveryRequired'] ? 'recovery_required' : ($held ? 'held_discrepancy' : ($pendingPark || $payment === null ? 'allocation_pending' : 'current'));
        $result = ['paymentRef' => $ref, 'sourceAuthority' => $observed->source_authority, 'sourceLineage' => $observed->source_lineage,
            'providerAccountRef' => $observed->provider_account_ref,
            'observed' => ['revision' => $observed->revision, 'state' => self::state(View::text($observed->state)),
                'authorizedTotal' => $money($observed->authorized_minor), 'capturedTotal' => $money($observed->captured_minor),
                'refundedTotal' => $money($observed->refunded_minor), 'voidedTotal' => $money($observed->voided_minor),
                'evidenceRefs' => array_values(array_unique($evidence)), 'observedAt' => View::timestamp($payload['observedAt'] ?? $observed->received_at)],
            'financialHold' => ['active' => $held, 'reasonCodes' => array_values(array_unique($reasons)), 'discrepancyRefs' => $refs],
            'exposure' => ['currency' => $observed->currency,
                'overCaptured' => $money(max(0, View::integer($observed->captured_minor) - View::integer($observed->authorized_minor))),
                'overRefunded' => $money(max(0, View::integer($observed->refunded_minor) - View::integer($observed->captured_minor)))],
            'spendable' => $money(0), 'projectionStatus' => $status, 'bootstrap' => ['status' => $recovery['bootstrapStatus']],
            // D48: a comparison window/digest is not a source-completeness certificate. Never invent a completed cursor.
            'reconciliation' => ['status' => $discrepancies->isEmpty() ? ($observed->refunded_minor > 0 ? 'pending' : 'not_started') : 'discrepancy']];
        if ($park !== null) {
            $result['pendingAllocationReason'] = $park->dependency_kind;
        } elseif ($inboxPark !== null) {
            $result['pendingAllocationReason'] = $inboxPark;
        } elseif ($payment === null) {
            $result['pendingAllocationReason'] = 'unapplied_observation';
        }
        if ($payment !== null) {
            $result['billingAccountRef'] = $payment->billing_account_ref;
            $result['reallocationRequired'] = $payment->reallocation_required;
            if (View::integer($payment->applied_revision) > 0) {
                $unallocated = max(0, View::integer($payment->captured_minor) - View::integer($payment->refunded_minor) - View::integer($payment->allocated_minor));
                $result['applied'] = ['revision' => $payment->applied_revision, 'state' => self::state(View::text($appliedState)),
                    'requested' => $money($payment->requested_minor), 'authorizedTotal' => $money($payment->authorized_minor),
                    'capturedTotal' => $money($payment->captured_minor), 'refundedTotal' => $money($payment->refunded_minor),
                    'allocated' => $money($payment->allocated_minor), 'unallocated' => $money($unallocated)];
                if ($status === 'current' && $payment->reallocation_required !== true) {
                    $result['spendable'] = $money($unallocated);
                }
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function allocations(string $operation, ?string $authority = null): array
    {
        $status = $this->payment($operation, $authority);
        $payment = $this->scoped('billium_payment')->where('operation_ref', $operation)->where('source_authority', $status['sourceAuthority'])->first();
        $receipts = [];
        if ($payment !== null) {
            $observed = $this->scoped('billium_observed_payment')->where('operation_ref', $operation)->where('source_authority', $payment->source_authority)->first()
                ?? throw self::unknown();
            $receiptIds = $this->scoped('billium_allocation')->where('payment_id', $payment->id)
                ->where('billing_account_ref', $payment->billing_account_ref)->pluck('receipt_ref')->all();
            $receiptIds = [...$receiptIds, ...$this->scoped('billium_payment_revision')->where('payment_id', $payment->id)->pluck('receipt_ref')->all()];
            $digests = $this->scoped('billium_observed_payment_revision')->where('observed_payment_id', $observed->id)->pluck('material_digest')->all();
            foreach ($this->scoped('billium_allocation_receipt')->where('billing_account_ref', $payment->billing_account_ref)
                ->where('currency', $payment->currency)->where(static function (Builder $q) use ($receiptIds, $digests): void {
                    // Economic digests include authority. This also associates legacy zero-part/held receipts, whose
                    // old receipt refs omitted authority, with the selected observed payment's immutable revisions.
                    $q->whereIn('receipt_ref', $receiptIds)->orWhere(static fn (Builder $observation): Builder => $observation
                        ->where('kind', 'payment_observation')->whereIn('semantic_digest', $digests));
                })->orderBy('id')->get() as $receipt) {
                $receipts[] = View::json($receipt->body);
            }
        }

        return ['operationRef' => $operation, 'projectionStatus' => $status['projectionStatus'], 'receipts' => $receipts];
    }

    public static function unknown(): ProblemException
    {
        return new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown ledger reference');
    }

    private static function state(string $state): string
    {
        return match ($state) {
            'unknown' => 'accepted-unknown', 'failed' => 'failed-terminal', default => $state,
        };
    }
}
