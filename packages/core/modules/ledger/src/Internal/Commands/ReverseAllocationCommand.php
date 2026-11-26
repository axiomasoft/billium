<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\Receipts\AllocationReceiptWriter;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

/**
 * Full immutable reversal of one original allocation (UNIQUE reverses_id) plus an optional remainder placed again from
 * the same source in the same Tx; history is never deleted (COV-289). billium_reverse_allocation owns the rows/counters.
 */
#[Internal]
final readonly class ReverseAllocationCommand
{
    public function __construct(private DatabaseManager $databases, private OperationRunner $runner, private AllocationReceiptWriter $writer) {}

    public static function validate(ReverseAllocation $command): void
    {
        AllocatePayload::text($command->commandKey, $command->scope->sellerRef, $command->scope->environment, $command->scope->billingAccountRef,
            $command->reversalRef, is_string($command->allocationRef) ? $command->allocationRef : null, $command->causeRef);
        if (is_int($command->allocationRef) && $command->allocationRef <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'an allocation journal id is positive');
        }
        AllocatePayload::environment($command->scope->environment);
        if ($command->remainder !== null) {
            AllocatePayload::positive($command->remainder);
        }
    }

    public static function digest(ReverseAllocation $command): string
    {
        return AllocatePayload::digest([
            'scope' => AllocatePayload::scope($command->scope), 'reversalRef' => $command->reversalRef, 'allocationRef' => $command->allocationRef,
            'causeRef' => $command->causeRef, 'remainder' => $command->remainder === null ? null : AllocatePayload::money($command->remainder),
        ]);
    }

    public function execute(TransactionContext $context, ReverseAllocation $command): LedgerReceipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        $this->writer->assertMonetary($context);
        self::validate($command);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        $connection = $this->databases->connection($context->connectionName());
        $read = function () use ($connection, $caller, $command): array {
            $rows = $connection->table('billium_allocation as a')->join('billium_obligation as o', 'o.id', '=', 'a.obligation_id')
                ->where('a.seller_ref', $caller->sellerRef)->where('a.environment', $caller->environment)
                ->where(is_int($command->allocationRef) ? 'a.id' : 'a.receipt_ref',
                    is_int($command->allocationRef) ? $command->allocationRef : 'allocation:'.$command->allocationRef)
                ->whereNull('a.reverses_id')->where('a.amount_minor', '>', 0)
                ->get(['a.id', 'a.receipt_ref', 'a.obligation_id', 'a.payment_id', 'a.customer_credit_id', 'a.settlement_intent_id', 'a.currency', 'o.obligation_ref', 'o.billed_invoice_id']);
            if (count($rows) !== 1) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown original allocation');
            }
            $row = $rows[0] ?? null;
            if (! is_object($row)) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown original allocation');
            }

            return [
                'id' => AllocationReceiptWriter::int($row->id),
                'receipt_ref' => is_string($row->receipt_ref) ? $row->receipt_ref : '',
                'obligation' => AllocationReceiptWriter::int($row->obligation_id),
                'obligation_ref' => is_string($row->obligation_ref) ? $row->obligation_ref : '',
                'invoice' => $row->billed_invoice_id === null ? null : AllocationReceiptWriter::int($row->billed_invoice_id),
                'payment' => $row->payment_id === null ? null : AllocationReceiptWriter::int($row->payment_id),
                'credit' => $row->customer_credit_id === null ? null : AllocationReceiptWriter::int($row->customer_credit_id),
                'intent' => $row->settlement_intent_id === null ? null : AllocationReceiptWriter::int($row->settlement_intent_id),
                'currency' => is_string($row->currency) ? $row->currency : '',
            ];
        };
        $before = $read();
        if ($command->remainder !== null && $command->remainder->currency->code !== $before['currency']) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'the remainder is in the currency of the original allocation');
        }
        $plan = new AllocateLockPlan([$before['intent']], [$before['obligation']], [$before['invoice']], [$before['payment']], [$before['credit']]);
        $plan->lock($context);
        if ($read() !== $before) {
            throw new LockPlanChanged('allocation rows changed');
        }
        $receiptRef = 'reversal:'.$command->reversalRef;
        [$receipt, $invoices] = $this->writer->apply($context, $connection, 'reverse_allocation', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $command->scope->billingAccountRef, $before['currency'],
            $before['id'], $command->reversalRef, $command->remainder->minor ?? 0, $command->causeRef, self::digest($command),
        ], 'ledger.allocation_reversal', $plan->invoices);
        if (! $receipt->replayed) {
            $body = $receipt->body();
            $revision = $connection->table('billium_obligation')->where('id', $before['obligation'])->value('revision');
            $invoice = $before['invoice'] === null ? null : ($invoices[$before['invoice']] ?? null);
            $this->writer->event($context, 'allocation.applied.v1', $before['obligation_ref'], AllocationReceiptWriter::int($revision), [
                'receipt_ref' => $receiptRef,
                'reverses_receipt_ref' => $before['receipt_ref'],
                'obligation_ref' => $before['obligation_ref'],
                'billing_account_ref' => $command->scope->billingAccountRef,
                'amount_minor' => '-'.(is_string($body['reversed_minor'] ?? null) ? $body['reversed_minor'] : ''),
                'remainder_minor' => (string) ($command->remainder->minor ?? 0),
                'currency' => $before['currency'],
                'cause' => $command->causeRef,
                'invoice_ref' => $invoice['ref'] ?? null,
                'invoice_status' => $invoice === null ? null : $invoice['status']->value,
            ]);
        }

        return $receipt;
    }
}
