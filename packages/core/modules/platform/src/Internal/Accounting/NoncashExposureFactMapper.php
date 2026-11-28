<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Accounting;

use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactEnvelope;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingResult;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Packman\Attributes\Internal;

/**
 * Maps NoncashExposure facts keyed by complete discrepancy basis fingerprint (0 I/O).
 */
#[Internal]
final readonly class NoncashExposureFactMapper
{
    public function __construct(
        private AccountingFactSupport $support,
    ) {}

    public function map(AccountingSourceRecord $record): AccountingMappingResult
    {
        $payload = $record->payload;
        $reason = AccountingFactSupport::nonEmptyString($payload['reason'] ?? null);

        $factKind = match ($record->eventType) {
            'exposure.over_refund', 'exposure.over_refund.v1' => AccountingFactKind::OverRefundExposure,
            'exposure.over_capture', 'exposure.over_capture.v1' => AccountingFactKind::OverCaptureExposure,
            default => match ($reason) {
                'over_refund' => AccountingFactKind::OverRefundExposure,
                'over_capture' => AccountingFactKind::OverCaptureExposure,
                default => AccountingFactKind::DiscrepancyExposure,
            },
        };

        $money = $this->support->extractMoney($payload, ['exposureMoney', 'money'], ['exposure_minor', 'exposureMinor', 'amount_minor', 'amountMinor']);
        if ($money === null || $money->minor <= 0) {
            return AccountingMappingResult::ignored('non_positive_exposure_amount');
        }

        $contributing = $this->normalizeContributingEffects($payload['contributingEffects'] ?? null);
        if ($contributing === []) {
            return AccountingMappingResult::ignored('missing_discrepancy_contributing_effects');
        }

        $rootOperationRef = AccountingFactSupport::nonEmptyString($payload['rootOperationRef'] ?? null)
            ?? AccountingFactSupport::nonEmptyString($payload['paymentRef'] ?? null)
            ?? $record->aggregateRef;
        $effectiveReason = $reason ?? $factKind->value;

        $basisFingerprint = $this->support->codec->digest([
            'sellerRef' => $record->sellerRef,
            'environment' => $record->environment,
            'factKind' => $factKind->value,
            'rootOperationRef' => $rootOperationRef,
            'reason' => $effectiveReason,
            'currency' => $money->currency->code,
            'exposureMinor' => $money->minor,
            'contributingEffects' => $contributing,
        ]);

        $sourceFactRef = 'fact:'.$factKind->value.':'.substr($basisFingerprint, 7, 32);
        $linkedCash = AccountingFactSupport::normalizeStringList($payload['linkedCashFactRefs'] ?? []);
        $originalFactRef = AccountingFactSupport::nonEmptyString($payload['originalFactRef'] ?? null);

        $metadata = AccountingFactSupport::filterNonNull([
            'rootOperationRef' => $rootOperationRef,
            'reason' => $effectiveReason,
            'discrepancyBasisDigest' => $basisFingerprint,
            'contributingEffects' => $contributing,
            'discrepancyRef' => AccountingFactSupport::nonEmptyString($payload['discrepancyRef'] ?? null),
            'observedRefundedMinor' => AccountingFactSupport::optionalInt($payload['observedRefundedMinor'] ?? null),
            'appliedRefundedMinor' => AccountingFactSupport::optionalInt($payload['appliedRefundedMinor'] ?? null),
            'appliedCapturedMinor' => AccountingFactSupport::optionalInt($payload['appliedCapturedMinor'] ?? null),
        ]);

        return AccountingMappingResult::mapped(new AccountingFactEnvelope(
            schemaVersion: AccountingFactEnvelope::SCHEMA_V1,
            sourceFactRef: $sourceFactRef,
            factKind: $factKind,
            category: AccountingFactCategory::NoncashExposure,
            installationRef: $record->installationRef,
            sellerRef: $record->sellerRef,
            environment: $record->environment,
            sourceLineage: $record->sourceLineage,
            economicEffectRef: "exposure:{$rootOperationRef}:".substr($basisFingerprint, 7, 16),
            money: $money,
            occurredAt: $record->occurredAt,
            snapshotDigest: $this->support->snapshotDigest($record, $sourceFactRef, $money, $metadata),
            originalFactRef: $originalFactRef,
            linkedCashFactRefs: $linkedCash,
            exceptional: true,
            metadata: $metadata,
        ));
    }

    /**
     * @return list<array{effectRef: string, revision: string, digest: string}>
     */
    private function normalizeContributingEffects(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                return [];
            }
            $effectRef = AccountingFactSupport::nonEmptyString($entry['providerEffectRef'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($entry['effectRef'] ?? null);
            $digest = AccountingFactSupport::nonEmptyString($entry['digest'] ?? null)
                ?? AccountingFactSupport::nonEmptyString($entry['evidenceDigest'] ?? null);
            $revRaw = $entry['revision'] ?? null;
            $revision = is_int($revRaw) ? (string) $revRaw : AccountingFactSupport::nonEmptyString($revRaw);
            if ($effectRef === null || $digest === null || $revision === null) {
                return [];
            }
            $normalized[] = [
                'effectRef' => $effectRef,
                'revision' => $revision,
                'digest' => $digest,
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => [$a['effectRef'], $a['revision'], $a['digest']] <=> [$b['effectRef'], $b['revision'], $b['digest']]);

        return $normalized;
    }
}
