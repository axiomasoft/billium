<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Data\Allocations\ReallocatePayment;
use Billium\Ledger\Data\Allocations\ReallocationMode;
use Billium\Ledger\Data\Runtime\LedgerReceipt;
use Billium\Ledger\Internal\PaymentSource;
use Billium\Ledger\Internal\Receipts\AllocationReceiptWriter;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/**
 * allocation.reallocate.v1: after a confirmed refund made the payment over-allocated (reallocation_required,
 * derived by CHECK), reverse its live allocations last-first with cause = refund until allocated = captured − refunded;
 * the overshoot of the last one is placed again. credit_note mode credits every reopened amount in the same Tx (debt
 * closed); reversal mode leaves the receivable open and authorizes no collection. The identity is the payment and the
 * refund state that required it, so a lost reply under another key replays the same receipt.
 */
#[Internal]
final readonly class ReallocatePaymentCommand
{
    public const string POLICY = 'refund/1';

    public function __construct(private DatabaseManager $databases, private OperationRunner $runner, private AllocationReceiptWriter $writer) {}

    public static function validate(ReallocatePayment $command): void
    {
        AllocatePayload::text($command->commandKey, $command->installationRef, $command->sellerRef, $command->environment, $command->paymentRef, $command->policyVersion);
        AllocatePayload::environment($command->environment);
        if (preg_match('/^[a-z_]+\/[0-9]+$/D', $command->policyVersion) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'policyVersion is name/version');
        }
    }

    public static function digest(ReallocatePayment $command): string
    {
        return AllocatePayload::digest([
            'installationRef' => $command->installationRef, 'sellerRef' => $command->sellerRef, 'environment' => $command->environment,
            'paymentRef' => $command->paymentRef, 'policyVersion' => $command->policyVersion, 'mode' => $command->mode->value,
        ]);
    }

    public function execute(TransactionContext $context, ReallocatePayment $command): LedgerReceipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        $this->writer->assertMonetary($context);
        self::validate($command);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->sellerRef || $caller->environment !== $command->environment || $caller->installationRef !== $command->installationRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'reallocation scope differs from caller');
        }
        $connection = $this->databases->connection($context->connectionName());
        $read = function () use ($connection, $caller, $command): array {
            $payment = PaymentSource::resolve($connection->table('billium_payment')->where('installation_ref', $caller->installationRef)
                ->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment), $command->paymentRef);
            if ($payment === null) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown payment');
            }
            $id = AllocationReceiptWriter::int($payment->id);
            $paymentRef = is_string($payment->payment_ref) ? $payment->payment_ref : throw new LogicException('Expected a payment reference');
            $sourceAuthority = is_string($payment->source_authority) ? $payment->source_authority : throw new LogicException('Expected a source authority');
            $obligations = [];
            $invoices = [];
            foreach ($connection->table('billium_allocation as a')->join('billium_obligation as o', 'o.id', '=', 'a.obligation_id')
                ->where('a.seller_ref', $caller->sellerRef)->where('a.environment', $caller->environment)->where('a.payment_id', $id)
                ->orderBy('a.id')->get(['a.id', 'o.id as obligation_id', 'o.billed_invoice_id']) as $row) {
                $obligations[] = AllocationReceiptWriter::int($row->obligation_id);
                if ($row->billed_invoice_id !== null) {
                    $invoices[] = AllocationReceiptWriter::int($row->billed_invoice_id);
                }
            }

            return [
                'id' => $id,
                'intent' => $payment->settlement_intent_id === null ? null : AllocationReceiptWriter::int($payment->settlement_intent_id),
                'refunded' => AllocationReceiptWriter::int($payment->refunded_minor),
                'payment_ref' => $paymentRef,
                'source_authority' => $sourceAuthority,
                'source_identity' => PaymentSource::identity($sourceAuthority, $paymentRef),
                'obligations' => $obligations,
                'invoices' => $invoices,
            ];
        };
        $before = $read();
        $plan = new AllocateLockPlan([$before['intent']], $before['obligations'], $before['invoices'], [$before['id']]);
        $plan->lock($context);
        if ($read() !== $before) {
            throw new LockPlanChanged('payment allocations changed');
        }
        // The refund state that made this reallocation necessary is part of its identity: a later refund is a new one.
        $reference = $before['source_identity'].'@'.$before['refunded'];
        if (strlen('reallocation:'.$reference.'#2147483647') > 200) {
            $reference = 'sha256:'.hash('sha256', $reference);
        }
        $digest = AllocatePayload::digest([
            'installationRef' => $caller->installationRef, 'sellerRef' => $caller->sellerRef, 'environment' => $caller->environment,
            'source' => $before['source_identity'], 'refunded' => $before['refunded'], 'mode' => $command->mode->value,
            'policyVersion' => $command->policyVersion,
        ]);
        [$receipt] = $this->writer->apply($context, $connection, 'reallocate_payment', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $before['id'], $reference, $command->mode->value,
            $command->policyVersion, $digest,
        ], 'ledger.reallocation', $plan->invoices);
        if (! $receipt->replayed) {
            $body = $receipt->body();
            $revision = $connection->table('billium_allocation')->where('seller_ref', $caller->sellerRef)->where('environment', $caller->environment)
                ->where('payment_id', $before['id'])->count();
            $common = [
                'receipt_ref' => 'reallocation:'.$reference,
                'payment_ref' => $before['payment_ref'],
                'source_authority' => $before['source_authority'],
                'billing_account_ref' => $body['billing_account_ref'] ?? null,
                'currency' => $body['currency'] ?? null,
                'mode' => $command->mode->value,
                'policy_version' => $command->policyVersion,
                'cause' => 'refund',
            ];
            $this->writer->event($context, 'allocation.applied.v1', $before['payment_ref'], $revision, [...$common, 'reversals' => $body['reversals'] ?? []]);
            if ($command->mode === ReallocationMode::CreditNote) {
                $this->writer->event($context, 'credit_note.issued.v1', $before['payment_ref'], $revision, [...$common, 'credit_notes' => $body['credit_notes'] ?? []]);
            }
        }

        return $receipt;
    }
}
