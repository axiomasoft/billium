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
 * Product-agnostic export mapping profile for recurring/subscription/annual-funding + periodic-grant artifacts.
 *
 * Enforces:
 * - IM-06: Mutable monthly subscriptions without frozen price/occurrences never infer historical recurring charges.
 * - IM-09: Exact proven frozenPriceSnapshot/currency/tax is retained after current plan changes; never uses current plan or display price.
 * - IM-13: Annual funding + 12 periodic grants never spawns 12 new monetary obligations.
 * - IM-16: Historical invoices preserve originalIssuer/sourceNumber without issuing a second Billium legal number.
 */
#[Internal]
final readonly class RecurringExportProfile implements SourceExportProfile
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
        return 'reference-recurring';
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

        // Mutable subscription state without frozen price/occurrences never implies historical recurring charges.
        if (in_array($recordType, ['mutable_subscription_state', 'recurring_subscription'], true)) {
            $missing = ['frozenPriceSnapshot', 'provenOccurrences', 'money'];
            $reasons = ['import.mutable_subscription_without_frozen_occurrences'];

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
                missingEvidence: $missing,
                reasonCodes: $reasons,
                partyRoles: $partyRoles,
                semanticExtra: [
                    'inferredRecurringCharges' => 0,
                    'collectionAllowed' => false,
                ],
                metadataExtra: $metadata,
            );
        }

        // Periodic entitlement/access grants remain nonfinancial references, never monetary obligations or balances.
        if (in_array($recordType, ['periodic_grant', 'entitlement_grant'], true)) {
            $grantMeta = [];
            if (is_array($rawRecord['nonMonetaryEntitlement'] ?? null)) {
                $grantMeta['nonMonetaryEntitlement'] = $rawRecord['nonMonetaryEntitlement'];
            }
            $grantMeta['syntheticObligationsCreated'] = 0;

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
                semanticExtra: $grantMeta,
                metadataExtra: $metadata,
            );
        }

        // Annual funding bundle with attached periodic grants emits AT MOST ONE proven obligation (never 12).
        if ($recordType === 'annual_funding_with_grants') {
            $moneyRes = $this->normalizer->parseOptionalMoney($rawRecord);
            $priceRes = $this->normalizer->parseFrozenPriceSnapshot($rawRecord);
            $missing = array_merge($moneyRes['missing'], $priceRes['missing']);
            $reasons = array_merge($moneyRes['reasons'], $priceRes['reasons']);
            if (! $this->normalizer->hasEvidenceKind($evidenceRefs, ['price_snapshot'])) {
                $missing[] = 'evidenceRefs.price_snapshot';
            }

            $attachedGrantCount = is_array($rawRecord['attachedGrants'] ?? null) ? count($rawRecord['attachedGrants']) : 0;
            $extra = [
                'attachedGrantCount' => $attachedGrantCount,
                'expandedGrantObligations' => 0,
                'ignoredCurrentPlanSnapshot' => isset($rawRecord['currentPlanSnapshot']),
            ];

            if ($moneyRes['money'] === null || $priceRes['snapshot'] === null || $missing !== []) {
                if ($reasons === []) {
                    $reasons[] = 'import.missing_proven_price_basis';
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
                    missingEvidence: $missing,
                    reasonCodes: $reasons,
                    partyRoles: $partyRoles,
                    semanticExtra: $extra,
                    metadataExtra: $metadata,
                );
            }

            return $this->normalizer->buildRecordInput(
                manifest: $manifest,
                codec: $codec,
                sourceRecordId: $recordId,
                sourceRecordRevision: $revision,
                recordKind: ImportRecordKind::ProvenObligation,
                eventBusinessDate: $eventDate,
                originalBusinessRefs: $origRefs,
                evidenceRefs: $evidenceRefs,
                money: $moneyRes['money'],
                frozenPriceSnapshot: $priceRes['snapshot'],
                fundingRefs: null,
                legalDocument: null,
                missingEvidence: [],
                reasonCodes: [],
                partyRoles: $partyRoles,
                semanticExtra: $extra,
                metadataExtra: $metadata,
            );
        }

        // Delegate standard proven/unanchored record types to GenericExportProfile while tagging profile metadata.
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
