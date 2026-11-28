<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Contracts\Data\Money;
use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Packman\Attributes\Internal;

/**
 * Maps unique authenticated actual provider cash effects (CashIn / CashOut) (0 I/O).
 */
#[Internal]
final readonly class ActualCashFactMapper
{
    public function __construct(
        private AccountingFactSupport $support,
    ) {}

    public function map(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;

        if (($payload['ambiguous'] ?? false) === true) {
            return AccountingMappingResult::ignored('ambiguous_aggregate_evidence');
        }
        $evidenceKind = AccountingFactSupport::nonEmptyString($payload['evidenceKind'] ?? null);
        if ($evidenceKind !== null && in_array($evidenceKind, ['aggregate_only', 'bounded_projection', 'allocation'], true)) {
            return AccountingMappingResult::ignored("non_actual_evidence_kind:{$evidenceKind}");
        }
        $derivedFrom = AccountingFactSupport::nonEmptyString($payload['derivedFrom'] ?? null);
        if ($derivedFrom !== null && in_array($derivedFrom, ['bounded_refunded_total', 'allocation', 'applied_projection'], true)) {
            return AccountingMappingResult::ignored("derived_from_non_actual_source:{$derivedFrom}");
        }

        $factKind = $this->resolveCashFactKind($record->eventType, $payload);
        if ($factKind === null) {
            return AccountingMappingResult::ignored('non_cash_operation_kind');
        }

        $status = AccountingFactSupport::nonEmptyString($payload['status'] ?? null);
        if ($record->eventType === 'payment.effect.outcome.v1') {
            if ($status === null || ! in_array($status, ['succeeded', 'confirmed'], true)) {
                return AccountingMappingResult::ignored('unconfirmed_effect_status:'.($status ?? 'missing'));
            }
        } elseif ($status !== null && ! in_array($status, ['succeeded', 'confirmed'], true)) {
            return AccountingMappingResult::ignored("unconfirmed_effect_status:{$status}");
        }

        $providerAccountRef = AccountingFactSupport::nonEmptyString($payload['providerAccountRef'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['merchantAccountRef'] ?? null);

        $providerEffectRef = $this->resolveProviderEffectRef($record->eventType, $payload);
        if ($providerAccountRef === null || $providerEffectRef === null) {
            return AccountingMappingResult::ignored('missing_authoritative_provider_effect_identity');
        }

        $money = $this->extractActualCashMoney($record->eventType, $payload);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('zero_or_missing_actual_cash_amount');
        }

        $sourceFactRef = $this->support->cashFactRef($record->sellerRef, $record->environment, $factKind, $providerAccountRef, $providerEffectRef);
        $economicEffectRef = "{$providerAccountRef}|{$providerEffectRef}";
        $originalFactRef = AccountingFactSupport::nonEmptyString($payload['originalFactRef'] ?? null);
        $exceptional = ($payload['exceptional'] ?? false) === true;

        $metadata = AccountingFactSupport::filterNonNull([
            'providerAccountRef' => $providerAccountRef,
            'providerEffectRef' => $providerEffectRef,
            'operationRef' => AccountingFactSupport::nonEmptyString($payload['operationRef'] ?? null),
            'originalOperationRef' => AccountingFactSupport::nonEmptyString($payload['originalOperationRef'] ?? null),
            'providerEvidenceRef' => AccountingFactSupport::nonEmptyString($payload['providerEvidenceRef'] ?? null),
            'bindingRevision' => AccountingFactSupport::nonEmptyString($payload['bindingRevision'] ?? null),
            'reasonCode' => AccountingFactSupport::nonEmptyString($payload['reasonCode'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: $factKind,
            category: $factKind->category(),
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: $economicEffectRef,
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: [],
            exceptional: $exceptional,
            metadata: $metadata,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCashFactKind(string $eventType, array $payload): ?AccountingFactKind
    {
        if ($eventType === 'payment.effect.outcome.v1') {
            $operationKind = AccountingFactSupport::nonEmptyString($payload['operationKind'] ?? null);

            return match ($operationKind) {
                'capture' => AccountingFactKind::PaymentCaptured,
                'refund' => AccountingFactKind::RefundSucceeded,
                default => null,
            };
        }

        return match ($eventType) {
            'payment.captured', 'payment.captured.v1' => AccountingFactKind::PaymentCaptured,
            'refund.succeeded', 'refund.succeeded.v1' => AccountingFactKind::RefundSucceeded,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProviderEffectRef(string $eventType, array $payload): ?string
    {
        if (array_key_exists('providerEffectRef', $payload)) {
            return AccountingFactSupport::nonEmptyString($payload['providerEffectRef']);
        }
        $stable = AccountingFactSupport::nonEmptyString($payload['stableEffectId'] ?? null);
        if ($stable !== null) {
            return $stable;
        }
        if ($eventType === 'payment.effect.outcome.v1') {
            return AccountingFactSupport::nonEmptyString($payload['providerEvidenceRef'] ?? null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractActualCashMoney(string $eventType, array $payload): ?Money
    {
        // Never derive cash from bounded/cumulative refundedTotal or capturedTotal.
        foreach (['effectMoney', 'actualMoney'] as $wireKey) {
            $money = AccountingFactSupport::parseMoneyWire($payload[$wireKey] ?? null);
            if ($money !== null) {
                return $money;
            }
        }

        if ($eventType === 'payment.effect.outcome.v1') {
            $effectTotal = AccountingFactSupport::nonEmptyString($payload['effectTotal'] ?? null);
            $currency = is_array($payload['money'] ?? null)
                ? AccountingFactSupport::nonEmptyString($payload['money']['currency'] ?? null)
                : AccountingFactSupport::nonEmptyString($payload['currency'] ?? null);
            if ($effectTotal === null || $currency === null) {
                return null;
            }

            return Money::parse($effectTotal, $currency);
        }

        return $this->support->extractMoney($payload, ['money'], ['effect_minor', 'effectMinor', 'amount_minor', 'amountMinor']);
    }
}
