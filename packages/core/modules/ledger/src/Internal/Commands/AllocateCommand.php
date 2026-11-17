<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\PaymentSource;
use Billium\Ledger\Internal\Receipts\AllocationReceiptWriter;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

/**
 * Explicit allocation of one funds source to one obligation (INV-09, COV-166). PHP authorizes, plans and prelocks
 * intent → obligation → invoice → funds; billium_allocate and its guard alone check the account/currency/scope, the
 * source budget (captured − refunded − allocated without hold or reallocation, or credit remaining) and the open
 * budget, append the journal row and move every counter. No network call happens here (COV-003).
 */
#[Internal]
final readonly class AllocateCommand
{
    /** Lineage of an explicit allocation; automatic allocation of observations uses pro_rata_open/1 (P3.9). */
    public const string POLICY = 'explicit/1';

    public function __construct(private DatabaseManager $databases, private OperationRunner $runner, private AllocationReceiptWriter $writer) {}

    public static function validate(Allocate $command): void
    {
        AllocatePayload::text($command->commandKey, $command->scope->sellerRef, $command->scope->environment, $command->scope->billingAccountRef,
            $command->allocationRef, $command->obligationRef, $command->paymentRef, $command->customerCreditRef, $command->cause);
        AllocatePayload::environment($command->scope->environment);
        AllocatePayload::positive($command->money);
    }

    public static function digest(Allocate $command): string
    {
        return AllocatePayload::digest([
            'scope' => AllocatePayload::scope($command->scope), 'allocationRef' => $command->allocationRef, 'obligationRef' => $command->obligationRef,
            'money' => AllocatePayload::money($command->money), 'paymentRef' => $command->paymentRef, 'customerCreditRef' => $command->customerCreditRef,
            'cause' => $command->cause,
        ]);
    }

    public function execute(TransactionContext $context, Allocate $command): LedgerReceipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        self::validate($command);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        $connection = $this->databases->connection($context->connectionName());
        $read = function () use ($connection, $caller, $command): array {
            // Selectors are scoped by the authenticated seller: another seller's rows are invisible (INV-14).
            $obligation = $connection->table('billium_obligation')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
                ->where('obligation_ref', $command->obligationRef)->first(['id', 'billed_invoice_id']);
            $source = $command->paymentRef !== null
                ? PaymentSource::resolve($connection->table('billium_payment')->where('installation_ref', $caller->installationRef)
                    ->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment), $command->paymentRef)
                : $connection->table('billium_customer_credit')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
                    ->where('credit_ref', $command->customerCreditRef)->first(['id']);
            if ($obligation === null || $source === null) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown obligation or funds source');
            }

            return [
                'obligation' => AllocationReceiptWriter::int($obligation->id),
                'invoice' => $obligation->billed_invoice_id === null ? null : AllocationReceiptWriter::int($obligation->billed_invoice_id),
                'source' => AllocationReceiptWriter::int($source->id),
                'intent' => ($source->settlement_intent_id ?? null) === null ? null : AllocationReceiptWriter::int($source->settlement_intent_id),
            ];
        };
        $before = $read();
        $payment = $command->paymentRef !== null;
        $plan = new AllocateLockPlan([$before['intent']], [$before['obligation']], [$before['invoice']], $payment ? [$before['source']] : [], $payment ? [] : [$before['source']]);
        $plan->lock($context);
        if ($read() !== $before) {
            throw new LockPlanChanged('allocation rows changed');
        }
        [$receipt, $invoices] = $this->writer->apply($context, $connection, 'allocate', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $command->scope->billingAccountRef, $command->money->currency->code,
            $before['obligation'], $payment ? $before['source'] : null, $payment ? null : $before['source'], $before['intent'], $command->money->minor,
            null, $command->cause, self::POLICY, 'allocation:'.$command->allocationRef, self::digest($command),
        ], 'ledger.allocation', $plan->invoices);
        if (! $receipt->replayed) {
            $revision = $connection->table('billium_obligation')->where('id', $before['obligation'])->value('revision');
            $invoice = $before['invoice'] === null ? null : ($invoices[$before['invoice']] ?? null);
            $this->writer->event($context, 'allocation.applied.v1', $command->obligationRef, AllocationReceiptWriter::int($revision), [
                'receipt_ref' => 'allocation:'.$command->allocationRef,
                'obligation_ref' => $command->obligationRef,
                'billing_account_ref' => $command->scope->billingAccountRef,
                'source_kind' => $payment ? 'payment' : 'customer_credit',
                'source_ref' => $command->paymentRef ?? $command->customerCreditRef,
                'amount_minor' => (string) $command->money->minor,
                'currency' => $command->money->currency->code,
                'policy_version' => self::POLICY,
                'invoice_ref' => $invoice['ref'] ?? null,
                'invoice_status' => $invoice === null ? null : $invoice['status']->value,
            ]);
        }

        return $receipt;
    }
}
