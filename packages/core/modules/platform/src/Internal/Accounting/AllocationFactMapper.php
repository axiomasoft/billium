<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Contracts\Data\Money;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Packman\Attributes\Internal;

/**
 * Maps NoncashAllocation facts (AllocationApplied, AllocationReversed) kept separate from cash facts (0 I/O).
 */
#[Internal]
final readonly class AllocationFactMapper
{
    public function __construct(
        private AccountingFactSupport $support,
    ) {}

    public function map(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        $reversesRef = AccountingFactSupport::nonEmptyString($payload['reverses_receipt_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['reversesAllocationRef'] ?? null);
        $isReversal = in_array($record->eventType, ['allocation.reversed', 'allocation.reversed.v1'], true)
            || $reversesRef !== null;

        if ($isReversal) {
            $reversalRef = AccountingFactSupport::nonEmptyString($payload['reversal_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['reversalRef'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['receipt_ref'] ?? null);
            if ($reversalRef === null) {
                return AccountingMappingResult::ignored('missing_allocation_reversal_ref');
            }

            $money = $this->support->extractMoney(
                $payload,
                ['money'],
                ['reversed_minor', 'reversedMinor', 'amount_minor', 'amountMinor'],
                allowNegativeMagnitude: true,
            );
            if ($money === null || $money->minor <= 0) {
                return AccountingMappingResult::ignored('zero_or_missing_allocation_reversal_amount');
            }
            // Ledger stores the negated original charge and re-places remainder_minor on the same receipt.
            $remainderMinor = AccountingFactSupport::parseIntValue($payload['remainder_minor'] ?? $payload['remainderMinor'] ?? null);
            if ($remainderMinor !== null && $remainderMinor > 0 && $remainderMinor < $money->minor) {
                $money = new Money($money->minor - $remainderMinor, $money->currency);
            }

            $sourceFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::AllocationReversed, $reversalRef);
            $originalFactRef = AccountingFactSupport::nonEmptyString($payload['originalFactRef'] ?? null);
            if ($originalFactRef === null && $reversesRef !== null) {
                // Ledger retains a remainder under the receipt of the reversal that produced it.
                $originalKind = str_starts_with($reversesRef, 'reversal:')
                    ? AccountingFactKind::AllocationReversed
                    : AccountingFactKind::AllocationApplied;
                $originalFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, $originalKind, $reversesRef);
            }

            $metadata = AccountingFactSupport::filterNonNull([
                'reversalRef' => $reversalRef,
                'reversesAllocationRef' => $reversesRef,
                'obligationRef' => AccountingFactSupport::nonEmptyString($payload['obligation_ref'] ?? null)
                    ?? AccountingFactSupport::nonEmptyString($payload['obligationRef'] ?? null),
                'invoiceRef' => AccountingFactSupport::nonEmptyString($payload['invoice_ref'] ?? null)
                    ?? AccountingFactSupport::nonEmptyString($payload['invoiceRef'] ?? null),
                'cause' => AccountingFactSupport::nonEmptyString($payload['cause'] ?? null),
            ]);

            return AccountingMappingResult::mapped(new AccountingFactEnvelope(
                schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
                sourceFactRef: $sourceFactRef,
                factKind: AccountingFactKind::AllocationReversed,
                category: AccountingFactCategory::NoncashAllocation,
                installationRef: $record->installationRef,
                sellerRef: $record->sellerRef,
                environment: $record->environment,
                sourceLineage: $record->sourceLineage,
                economicEffectRef: $reversalRef,
                money: $money,
                occurredAt: $record->occurredAt,
                snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
                originalFactRef: $originalFactRef,
                linkedCashFactRefs: AccountingFactSupport::normalizeStringList($payload['linkedCashFactRefs'] ?? []),
                exceptional: false,
                metadata: $metadata,
            ));
        }

        $allocationRef = AccountingFactSupport::nonEmptyString($payload['receipt_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['allocation_ref'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['allocationRef'] ?? null);
        if ($allocationRef === null) {
            return AccountingMappingResult::ignored('missing_allocation_ref');
        }

        $money = $this->support->extractMoney($payload, ['money'], ['amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_allocation_amount');
        }

        $sourceFactRef = $this->support->namedFactRef($record->sellerRef, $record->environment, AccountingFactKind::AllocationApplied, $allocationRef);
        $linkedCash = AccountingFactSupport::normalizeStringList($payload['linkedCashFactRefs'] ?? []);
        $singleLinked = AccountingFactSupport::nonEmptyString($payload['sourceCashFactRef'] ?? null);
        if ($singleLinked !== null && ! in_array($singleLinked, $linkedCash, true)) {
            $linkedCash[] = $singleLinked;
            sort($linkedCash, SORT_STRING);
        }

        $metadata = AccountingFactSupport::filterNonNull([
            'allocationRef' => $allocationRef,
            'obligationRef' => AccountingFactSupport::nonEmptyString($payload['obligation_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['obligationRef'] ?? null),
            'invoiceRef' => AccountingFactSupport::nonEmptyString($payload['invoice_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['invoiceRef'] ?? null),
            'sourceKind' => AccountingFactSupport::nonEmptyString($payload['source_kind'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['sourceKind'] ?? null),
            'sourceRef' => AccountingFactSupport::nonEmptyString($payload['source_ref'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($payload['sourceRef'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: AccountingFactKind::AllocationApplied,
            category: AccountingFactCategory::NoncashAllocation,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $allocationRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            linkedCashFactRefs: $linkedCash,
            exceptional: false,
            metadata: $metadata,
        ));
    }
}
