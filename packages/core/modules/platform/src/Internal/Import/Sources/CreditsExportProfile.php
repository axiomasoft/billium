<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import\Sources;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Override;
use Packman\Attributes\Internal;

/**
 * Product-agnostic export mapping profile for host non-monetary credits, rewards and hybrid billing artifacts.
 *
 * Enforces:
 * - IM-07: Host non-monetary credits/grants never become monetary balance.
 * - IM-08: Host non-currency reward quantities never become ISO currency.
 * - IM-01/IM-09/IM-16: Missing monetary evidence yields unanchored_evidence; proven prices and historical invoice numbers remain intact.
 */
#[Internal]
final readonly class CreditsExportProfile implements SourceExportProfile
{
    private GenericExportProfile $fallback;

    public function __construct(
        private ExportRecordNormalizer $normalizer = new ExportRecordNormalizer,
        private string $version = '1.0.0',
    ) {
        $this->fallback = new GenericExportProfile($this->normalizer, $this->version);
    }

    #[Override]
    public function profileId(): string
    {
        return 'reference-credits';
    }

    #[Override]
    public function profileVersion(): string
    {
        return $this->version;
    }

    #[Override]
    public function supportedSchema(): string
    {
        return GenericExportProfile::SCHEMA_V1;
    }

    #[Override]
    public function normalizeRecord(array $rawRecord, ImportManifest $manifest, Codec $codec): ImportRecordInput
    {
        $this->normalizer->assertNoExportedCredentials($rawRecord, 'record');

        $recordId = $this->normalizer->requireRecordId($rawRecord);
        $revision = $this->normalizer->requireRecordRevision($rawRecord);
        $rawType = $rawRecord['recordType'] ?? $rawRecord['recordKind'] ?? 'unanchored_evidence';
        $recordType = is_string($rawType) ? $rawType : 'unanchored_evidence';

        $origRefs = $this->normalizer->extractOriginalBusinessRefs($rawRecord);
        $partyRoles = $this->normalizer->extractPartyRoles($rawRecord, $manifest);
        $evidenceRefs = $this->normalizer->parseEvidenceRefs($rawRecord);
        $eventDate = $this->normalizer->parseEventBusinessDate($rawRecord);

        $metadata = [
            'profileId' => $this->profileId(),
            'profileVersion' => $this->profileVersion(),
            'rawRecordType' => $recordType,
        ];

        if ($eventDate === null) {
            return $this->normalizer->buildRecordInput(
                manifest: $manifest,
                codec: $codec,
                sourceRecordId: $recordId,
                sourceRecordRevision: $revision,
                recordKind: ImportRecordKind::UnanchoredEvidence,
                eventBusinessDate: $manifest->exportCutoff,
                originalBusinessRefs: $origRefs,
                evidenceRefs: $evidenceRefs,
                money: null,
                frozenPriceSnapshot: null,
                fundingRefs: null,
                legalDocument: null,
                missingEvidence: ['eventBusinessDate'],
                reasonCodes: ['import.missing_event_business_date'],
                partyRoles: $partyRoles,
                semanticExtra: ['eventBusinessDateAbsent' => true],
                metadataExtra: $metadata,
            );
        }

        // Host non-monetary credits/grants NEVER become monetary balance.
        if (in_array($recordType, ['credit_grant', 'bonus_grant', 'nonmonetary_credit', 'credit_balance'], true)) {
            $claimedAsMoney = ($rawRecord['monetaryClaim'] ?? false) === true || $recordType === 'credit_balance';
            $entitlement = is_array($rawRecord['nonMonetaryEntitlement'] ?? null)
                ? $rawRecord['nonMonetaryEntitlement']
                : [];

            if ($claimedAsMoney) {
                return $this->normalizer->buildRecordInput(
                    manifest: $manifest,
                    codec: $codec,
                    sourceRecordId: $recordId,
                    sourceRecordRevision: $revision,
                    recordKind: ImportRecordKind::UnanchoredEvidence,
                    eventBusinessDate: $eventDate,
                    originalBusinessRefs: $origRefs,
                    evidenceRefs: $evidenceRefs,
                    money: null,
                    frozenPriceSnapshot: null,
                    fundingRefs: null,
                    legalDocument: null,
                    missingEvidence: ['iso4217_currency', 'authoritative_monetary_evidence'],
                    reasonCodes: ['import.nonmonetary_credit_not_money'],
                    partyRoles: $partyRoles,
                    semanticExtra: [
                        'nonMonetaryEntitlement' => $entitlement,
                        'monetaryBalancePromoted' => false,
                    ],
                    metadataExtra: $metadata,
                );
            }

            return $this->normalizer->buildRecordInput(
                manifest: $manifest,
                codec: $codec,
                sourceRecordId: $recordId,
                sourceRecordRevision: $revision,
                recordKind: ImportRecordKind::NonfinancialAgreementReference,
                eventBusinessDate: $eventDate,
                originalBusinessRefs: $origRefs,
                evidenceRefs: $evidenceRefs,
                money: null,
                frozenPriceSnapshot: null,
                fundingRefs: null,
                legalDocument: null,
                missingEvidence: [],
                reasonCodes: [],
                partyRoles: $partyRoles,
                semanticExtra: [
                    'nonMonetaryEntitlement' => $entitlement,
                    'monetaryBalancePromoted' => false,
                ],
                metadataExtra: $metadata,
            );
        }

        // Host non-currency reward quantities NEVER become ISO currency.
        if (in_array($recordType, ['reward_quantity', 'loyalty_points', 'noncurrency_reward', 'nonmonetary_reward_reference'], true)) {
            $reward = is_array($rawRecord['nonMonetaryEntitlement'] ?? null)
                ? $rawRecord['nonMonetaryEntitlement']
                : [];
            $isPureReference = $recordType === 'nonmonetary_reward_reference' && ($rawRecord['monetaryClaim'] ?? false) !== true;

            if ($isPureReference) {
                return $this->normalizer->buildRecordInput(
                    manifest: $manifest,
                    codec: $codec,
                    sourceRecordId: $recordId,
                    sourceRecordRevision: $revision,
                    recordKind: ImportRecordKind::NonfinancialAgreementReference,
                    eventBusinessDate: $eventDate,
                    originalBusinessRefs: $origRefs,
                    evidenceRefs: $evidenceRefs,
                    money: null,
                    frozenPriceSnapshot: null,
                    fundingRefs: null,
                    legalDocument: null,
                    missingEvidence: [],
                    reasonCodes: [],
                    partyRoles: $partyRoles,
                    semanticExtra: [
                        'nonMonetaryReward' => $reward,
                        'currencyPromoted' => false,
                    ],
                    metadataExtra: $metadata,
                );
            }

            return $this->normalizer->buildRecordInput(
                manifest: $manifest,
                codec: $codec,
                sourceRecordId: $recordId,
                sourceRecordRevision: $revision,
                recordKind: ImportRecordKind::UnanchoredEvidence,
                eventBusinessDate: $eventDate,
                originalBusinessRefs: $origRefs,
                evidenceRefs: $evidenceRefs,
                money: null,
                frozenPriceSnapshot: null,
                fundingRefs: null,
                legalDocument: null,
                missingEvidence: ['iso4217_currency', 'authoritative_monetary_evidence'],
                reasonCodes: ['import.noncurrency_reward_not_currency'],
                partyRoles: $partyRoles,
                semanticExtra: [
                    'nonMonetaryReward' => $reward,
                    'currencyPromoted' => false,
                ],
                metadataExtra: $metadata,
            );
        }

        $normalized = $this->fallback->normalizeRecord($rawRecord, $manifest, $codec);
        $payload = $normalized->payload;
        $payload['mapperMetadata'] = $metadata;

        return new ImportRecordInput(
            identity: $normalized->identity,
            batchId: $normalized->batchId,
            sourceExportRevision: $normalized->sourceExportRevision,
            exportCutoff: $normalized->exportCutoff,
            manifestDigest: $normalized->manifestDigest,
            sourceRecordRevision: $normalized->sourceRecordRevision,
            eventBusinessDate: $normalized->eventBusinessDate,
            payloadDigest: $normalized->payloadDigest,
            originalBusinessRefs: $normalized->originalBusinessRefs,
            evidenceRefs: $normalized->evidenceRefs,
            money: $normalized->money,
            frozenPriceSnapshot: $normalized->frozenPriceSnapshot,
            fundingRefs: $normalized->fundingRefs,
            legalDocument: $normalized->legalDocument,
            importPolicyVersion: $normalized->importPolicyVersion,
            sourceFactRef: $normalized->sourceFactRef,
            missingEvidence: $normalized->missingEvidence,
            payload: $payload,
        );
    }
}
