<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Packman\Attributes\Internal;

/**
 * Maps Receivable facts (InvoiceIssued, CreditNoteIssued, WriteOffRecorded) from bounded ledger projections (0 I/O).
 */
#[Internal]
final readonly class ReceivableFactMapper
{
    public function __construct(
        private AccountingFactSupport $support,
    ) {}

    public function mapInvoiceIssued(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        if (($payload['bounded'] ?? true) === false || ($payload['projectionState'] ?? null) === 'held_inconsistent') {
            return AccountingMappingResult::ignored('unbounded_receivable_projection');
        }

        $invoiceRef = AccountingFactSupport::nonEmptyString($payload['invoice_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['invoiceRef'] ?? null)
            ?? $record->aggregateRef;

        $money = $this->support->extractMoney($payload, ['money'], ['total_minor', 'totalMinor', 'amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_invoice_amount');
        }

        $sourceFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $invoiceRef);
        $originalFactRef = AccountingFactSupport::nonEmptyString($payload['originalFactRef'] ?? null);
        if ($originalFactRef === null) {
            $replacesInvoiceRef = AccountingFactSupport::nonEmptyString($payload['replaces_invoice_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['replacesInvoiceRef'] ?? null);
            if ($replacesInvoiceRef !== null) {
                $originalFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $replacesInvoiceRef);
            }
        }

        $metadata = AccountingFactSupport::filterNonNull([
            'invoiceRef' => $invoiceRef,
            'billingAccountRef' => AccountingFactSupport::nonEmptyString($payload['billing_account_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['billingAccountRef'] ?? null),
            'legalDocumentRef' => AccountingFactSupport::nonEmptyString($payload['legal_document_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['legalDocumentRef'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::InvoiceIssued,
            category: AccountingFactCategory::Receivable,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $invoiceRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: false,
            metadata: $metadata,
        ));
    }

    public function mapCreditNoteIssued(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        if (($payload['bounded'] ?? true) === false || ($payload['projectionState'] ?? null) === 'held_inconsistent') {
            return AccountingMappingResult::ignored('unbounded_receivable_projection');
        }

        $creditNoteRef = AccountingFactSupport::nonEmptyString($payload['credit_note_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['creditNoteRef'] ?? null);
        if ($creditNoteRef === null) {
            return AccountingMappingResult::ignored('missing_credit_note_ref');
        }

        $money = $this->support->extractMoney($payload, ['money'], ['total_minor', 'totalMinor', 'amount_minor', 'amountMinor'])
            ?? $this->sumLineMinorAmounts($payload['lines'] ?? null, AccountingFactSupport::nonEmptyString($payload['currency'] ?? null));
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_credit_note_amount');
        }

        $invoiceRef = AccountingFactSupport::nonEmptyString($payload['invoice_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['invoiceRef'] ?? null);

        $sourceFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::CreditNoteIssued, $creditNoteRef);
        $originalFactRef = AccountingFactSupport::nonEmptyString($payload['originalFactRef'] ?? null);
        if ($originalFactRef === null && $invoiceRef !== null) {
            $originalFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $invoiceRef);
        }

        $metadata = AccountingFactSupport::filterNonNull([
            'creditNoteRef' => $creditNoteRef,
            'invoiceRef' => $invoiceRef,
            'billingAccountRef' => AccountingFactSupport::nonEmptyString($payload['billing_account_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['billingAccountRef'] ?? null),
            'mode' => AccountingFactSupport::nonEmptyString($payload['mode'] ?? null),
            'legalDocumentRef' => AccountingFactSupport::nonEmptyString($payload['legal_document_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['legalDocumentRef'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::CreditNoteIssued,
            category: AccountingFactCategory::Receivable,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $creditNoteRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: false,
            metadata: $metadata,
        ));
    }

    public function mapWriteOffRecorded(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        if (($payload['bounded'] ?? true) === false || ($payload['projectionState'] ?? null) === 'held_inconsistent') {
            return AccountingMappingResult::ignored('unbounded_receivable_projection');
        }

        $writeOffRef = AccountingFactSupport::nonEmptyString($payload['write_off_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['writeOffRef'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['receipt_ref'] ?? null)
            ?? $record->aggregateRef;

        $money = $this->support->extractMoney($payload, ['money'], ['write_off_minor', 'writeOffMinor', 'total_minor', 'totalMinor', 'amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_write_off_amount');
        }

        $invoiceRef = AccountingFactSupport::nonEmptyString($payload['invoice_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['invoiceRef'] ?? null);

        $sourceFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::WriteOffRecorded, $writeOffRef);
        $originalFactRef = AccountingFactSupport::nonEmptyString($payload['originalFactRef'] ?? null);
        if ($originalFactRef === null && $invoiceRef !== null) {
            $originalFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::InvoiceIssued, $invoiceRef);
        }

        $metadata = AccountingFactSupport::filterNonNull([
            'writeOffRef' => $writeOffRef,
            'invoiceRef' => $invoiceRef,
            'billingAccountRef' => AccountingFactSupport::nonEmptyString($payload['billing_account_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['billingAccountRef'] ?? null),
            'reason' => AccountingFactSupport::nonEmptyString($payload['reason'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::WriteOffRecorded,
            category: AccountingFactCategory::Receivable,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $writeOffRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: false,
            metadata: $metadata,
        ));
    }

    private function sumLineMinorAmounts(mixed $lines, ?string $currencyCode): ?Money
    {
        if (! is_array($lines) || $lines === [] || $currencyCode === null) {
            return null;
        }
        $sum = 0;
        $counted = 0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $minor = AccountingFactSupport::parseIntValue($line['amount_minor'] ?? $line['amountMinor'] ?? null);
            if ($minor === null || $minor <= 0) {
                continue;
            }
            if ($sum > PHP_INT_MAX - $minor) {
                return null;
            }
            $sum += $minor;
            $counted++;
        }

        return $counted > 0 ? new Money($sum, Currency::of($currencyCode)) : null;
    }
}
