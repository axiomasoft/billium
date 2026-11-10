<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Ledger\Data\CreditNoteLine;
use Billium\Ledger\Data\CreditNoteMode;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\LedgerReceipt;
use Billium\Ledger\Internal\Receipts\AllocationReceiptWriter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Credit note on an issued invoice (COV-167, COV-174): open_only reduces open; reverse_allocations first releases live
 * allocations of the line (LIFO, full reversal plus remainder) and credits without exceeding due. It is the same
 * routine for the public root and for P4/P6 participants (negative late delta, legal reference) in their root Tx.
 */
final readonly class IssueCreditNoteCommand
{
    public const int MAX_LINES = 500;

    public function __construct(private DatabaseManager $databases, private OperationRunner $runner, private AllocationReceiptWriter $writer) {}

    public static function validate(IssueCreditNote $command): void
    {
        AllocatePayload::text($command->commandKey, $command->scope->sellerRef, $command->scope->environment, $command->scope->billingAccountRef,
            $command->creditNoteRef, $command->invoiceRef, $command->reason, $command->legalDocumentRef);
        AllocatePayload::environment($command->scope->environment);
        if (str_contains($command->creditNoteRef, '#')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'creditNoteRef cannot contain # (line suffix)');
        }
        $refs = [];
        $currencies = [];
        foreach ($command->lines as $line) {
            AllocatePayload::text($line->obligationRef);
            AllocatePayload::positive($line->money);
            $refs[] = $line->obligationRef;
            $currencies[$line->money->currency->code] = true;
        }
        if ($refs === [] || count($refs) > self::MAX_LINES || count($refs) !== count(array_unique($refs))) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'a credit note has 1..'.self::MAX_LINES.' distinct obligation lines');
        }
        if (count($currencies) !== 1) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'credit note lines share one currency');
        }
    }

    public static function digest(IssueCreditNote $command): string
    {
        return AllocatePayload::digest([
            'scope' => AllocatePayload::scope($command->scope), 'creditNoteRef' => $command->creditNoteRef, 'invoiceRef' => $command->invoiceRef,
            'mode' => $command->mode->value, 'reason' => $command->reason, 'legalDocumentRef' => $command->legalDocumentRef,
            'lines' => array_map(static fn (CreditNoteLine $line): array => ['obligationRef' => $line->obligationRef, 'money' => AllocatePayload::money($line->money)], $command->lines),
        ]);
    }

    public function execute(TransactionContext $context, IssueCreditNote $command): LedgerReceipt
    {
        $this->runner->assertCurrent($context, 'ledger');
        $this->writer->assertMonetary($context);
        self::validate($command);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }
        $connection = $this->databases->connection($context->connectionName());
        $read = fn (): array => $this->read($connection, $caller->sellerRef, $caller->environment, $command);
        $before = $read();
        if ($before['currency'] !== $command->lines[0]->money->currency->code) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'credit note currency differs from the invoice');
        }
        $plan = new AllocateLockPlan($before['intents'], array_values($before['obligations']), [$before['invoice'], ...$before['invoices']], $before['payments'], $before['credits']);
        $plan->lock($context);
        if ($read() !== $before) {
            throw new LockPlanChanged('credit note rows changed');
        }
        $lines = [];
        foreach ($command->lines as $line) {
            $lines[] = ['obligation_id' => $before['obligations'][$line->obligationRef], 'amount_minor' => $line->money->minor];
        }
        [$receipt, $invoices] = $this->writer->apply($context, $connection, 'issue_credit_note', [
            $caller->installationRef, $caller->sellerRef, $caller->environment, $command->scope->billingAccountRef, $before['currency'],
            $before['invoice'], $command->creditNoteRef, $command->mode->value, json_encode($lines, JSON_THROW_ON_ERROR), $command->reason,
            $command->legalDocumentRef, self::digest($command),
        ], 'ledger.credit_note', $plan->invoices);
        if (! $receipt->replayed) {
            $invoice = $invoices[$before['invoice']] ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown invoice');
            $body = $receipt->body();
            $this->writer->event($context, 'credit_note.issued.v1', $command->invoiceRef, $invoice['revision'], [
                'receipt_ref' => 'credit-note:'.$command->creditNoteRef,
                'credit_note_ref' => $command->creditNoteRef,
                'invoice_ref' => $command->invoiceRef,
                'billing_account_ref' => $command->scope->billingAccountRef,
                'mode' => $command->mode->value,
                'currency' => $before['currency'],
                'lines' => $body['lines'] ?? [],
                'legal_document_ref' => $command->legalDocumentRef,
                'invoice_status' => $invoice['status']->value,
            ]);
        }

        return $receipt;
    }

    /** @return array{invoice: int, currency: string, obligations: array<string, int>, invoices: list<int>, intents: list<int>, payments: list<int>, credits: list<int>} */
    public function correctionPlan(TransactionContext $context, IssueCreditNote $command): array
    {
        $this->runner->assertCurrent($context, 'ledger');
        $this->writer->assertMonetary($context);
        self::validate($command);
        $caller = $context->caller();
        if ($caller->sellerRef !== $command->scope->sellerRef || $caller->environment !== $command->scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'participant scope differs from caller');
        }

        return $this->read($this->databases->connection($context->connectionName()), $caller->sellerRef, $caller->environment, $command);
    }

    /**
     * @return array{invoice: int, currency: string, obligations: array<string, int>, invoices: list<int>, intents: list<int>, payments: list<int>, credits: list<int>}
     */
    private function read(Connection $connection, string $seller, string $environment, IssueCreditNote $command): array
    {
        $invoice = $connection->table('billium_invoice')->where('seller_ref', $seller)->where('environment', $environment)->where('invoice_ref', $command->invoiceRef)->first(['id', 'currency']);
        $refs = array_map(static fn (CreditNoteLine $line): string => $line->obligationRef, $command->lines);
        $rows = $connection->table('billium_obligation')->where('seller_ref', $seller)->where('environment', $environment)->whereIn('obligation_ref', $refs)->orderBy('id')->get(['id', 'obligation_ref', 'billed_invoice_id']);
        if ($invoice === null || count($rows) !== count($refs)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'unknown invoice or credited obligation');
        }
        $obligations = [];
        $invoices = [];
        foreach ($rows as $row) {
            $obligations[is_string($row->obligation_ref) ? $row->obligation_ref : ''] = AllocationReceiptWriter::int($row->id);
            if ($row->billed_invoice_id !== null) {
                $invoices[] = AllocationReceiptWriter::int($row->billed_invoice_id);
            }
        }
        ksort($obligations, SORT_STRING);
        $sources = ['intents' => [], 'payments' => [], 'credits' => []];
        if ($command->mode === CreditNoteMode::ReverseAllocations) {
            foreach ($connection->table('billium_allocation')->where('seller_ref', $seller)->where('environment', $environment)
                ->whereIn('obligation_id', array_values($obligations))->orderBy('id')->get(['settlement_intent_id', 'payment_id', 'customer_credit_id']) as $row) {
                foreach (['intents' => $row->settlement_intent_id, 'payments' => $row->payment_id, 'credits' => $row->customer_credit_id] as $set => $id) {
                    if ($id !== null) {
                        $sources[$set][] = AllocationReceiptWriter::int($id);
                    }
                }
            }
        }

        return [
            'invoice' => AllocationReceiptWriter::int($invoice->id),
            'currency' => is_string($invoice->currency) ? $invoice->currency : '',
            'obligations' => $obligations,
            'invoices' => self::unique($invoices),
            'intents' => self::unique($sources['intents']),
            'payments' => self::unique($sources['payments']),
            'credits' => self::unique($sources['credits']),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function unique(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
