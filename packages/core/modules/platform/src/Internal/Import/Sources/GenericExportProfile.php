<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import\Sources;

use Billium\Contracts\Contracts\Codec;
use Billium\Platform\Data\Import\ImportEvidenceRef;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Override;
use Packman\Attributes\Internal;

/**
 * Default product-agnostic export mapping profile for source.export.v1 records.
 *
 * Promotes records to proven_* classes only when exact authoritative evidence is present;
 * otherwise classifies them explicitly as unanchored_evidence without fabricating defaults.
 */
#[Internal]
final readonly class GenericExportProfile implements SourceExportProfile
{
    public const string SCHEMA_V1 = 'source.export.v1';

    public function __construct(
        private ExportRecordNormalizer $normalizer = new ExportRecordNormalizer,
        private string $version = '1.0.0',
    ) {}

    #[Override]
    public function profileId(): string
    {
        return 'generic';
    }

    #[Override]
    public function profileVersion(): string
    {
        return $this->version;
    }

    #[Override]
    public function supportedSchema(): string
    {
        return self::SCHEMA_V1;
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

        // Missing eventBusinessDate cannot be substituted with "today" — classify as unanchored_evidence anchored to exportCutoff for schema transport only.
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

        return match ($recordType) {
            'obligation', 'proven_obligation' => $this->normalizeObligation($rawRecord, $manifest, $codec, $recordId, $revision, $eventDate, $origRefs, $evidenceRefs, $partyRoles, $metadata),
            'invoice_history', 'historical_invoice', 'proven_invoice_history' => $this->normalizeInvoiceHistory($rawRecord, $manifest, $codec, $recordId, $revision, $eventDate, $origRefs, $evidenceRefs, $partyRoles, $metadata),
            'payment_evidence', 'proven_payment_evidence' => $this->normalizeFundingEvidence($rawRecord, $manifest, $codec, $recordId, $revision, $eventDate, $origRefs, $evidenceRefs, $partyRoles, $metadata, false),
            'refund_evidence', 'proven_refund_evidence' => $this->normalizeFundingEvidence($rawRecord, $manifest, $codec, $recordId, $revision, $eventDate, $origRefs, $evidenceRefs, $partyRoles, $metadata, true),
            'allocation', 'proven_allocation' => $this->normalizeAllocation($rawRecord, $manifest, $codec, $recordId, $revision, $eventDate, $origRefs, $evidenceRefs, $partyRoles, $metadata),
            'agreement_reference', 'nonfinancial_agreement_reference' => $this->normalizer->buildRecordInput(
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
                semanticExtra: [],
                metadataExtra: $metadata,
            ),
            default => $this->normalizeUnanchored($rawRecord, $manifest, $codec, $recordId, $revision, $eventDate, $origRefs, $evidenceRefs, $partyRoles, $metadata),
        };
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @param  array<string, string>  $origRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, string>  $partyRoles
     * @param  array<string, mixed>  $metadata
     */
    private function normalizeObligation(
        array $rawRecord,
        ImportManifest $manifest,
        Codec $codec,
        string $recordId,
        int $revision,
        \DateTimeImmutable $eventDate,
        array $origRefs,
        array $evidenceRefs,
        array $partyRoles,
        array $metadata,
    ): ImportRecordInput {
        $moneyRes = $this->normalizer->parseOptionalMoney($rawRecord);
        $priceRes = $this->normalizer->parseFrozenPriceSnapshot($rawRecord);
        $missing = array_merge($moneyRes['missing'], $priceRes['missing']);
        $reasons = array_merge($moneyRes['reasons'], $priceRes['reasons']);

        if (! $this->normalizer->hasEvidenceKind($evidenceRefs, ['price_snapshot'])) {
            $missing[] = 'evidenceRefs.price_snapshot';
        }

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
                semanticExtra: ['claimedRecordKind' => ImportRecordKind::ProvenObligation->value],
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
            semanticExtra: [],
            metadataExtra: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @param  array<string, string>  $origRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, string>  $partyRoles
     * @param  array<string, mixed>  $metadata
     */
    private function normalizeInvoiceHistory(
        array $rawRecord,
        ImportManifest $manifest,
        Codec $codec,
        string $recordId,
        int $revision,
        \DateTimeImmutable $eventDate,
        array $origRefs,
        array $evidenceRefs,
        array $partyRoles,
        array $metadata,
    ): ImportRecordInput {
        $legalRes = $this->normalizer->parseHistoricalLegalDocument($rawRecord);
        $moneyRes = $this->normalizer->parseOptionalMoney($rawRecord);

        if ($legalRes['legalDocument'] === null) {
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
                missingEvidence: $legalRes['missing'],
                reasonCodes: $legalRes['reasons'],
                partyRoles: $partyRoles,
                semanticExtra: ['claimedRecordKind' => ImportRecordKind::ProvenInvoiceHistory->value],
                metadataExtra: $metadata,
            );
        }

        // Preserve originalIssuer & sourceNumber verbatim into originalBusinessRefs too; never synthesize a Billium legal number.
        $doc = $legalRes['legalDocument'];
        if (is_string($doc['originalIssuer'] ?? null)) {
            $origRefs['originalIssuer'] = $doc['originalIssuer'];
        }
        if (is_string($doc['sourceNumber'] ?? null)) {
            $origRefs['sourceNumber'] = $doc['sourceNumber'];
        }

        return $this->normalizer->buildRecordInput(
            manifest: $manifest,
            codec: $codec,
            sourceRecordId: $recordId,
            sourceRecordRevision: $revision,
            recordKind: ImportRecordKind::ProvenInvoiceHistory,
            eventBusinessDate: $eventDate,
            originalBusinessRefs: $origRefs,
            evidenceRefs: $evidenceRefs,
            money: $moneyRes['money'],
            frozenPriceSnapshot: null,
            fundingRefs: null,
            legalDocument: $doc,
            missingEvidence: [],
            reasonCodes: [],
            partyRoles: $partyRoles,
            semanticExtra: [
                'secondBilliumLegalNumberIssued' => false,
                'historicalEvidenceOnly' => $doc['legalOwnershipConfirmed'] === false,
            ],
            metadataExtra: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @param  array<string, string>  $origRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, string>  $partyRoles
     * @param  array<string, mixed>  $metadata
     */
    private function normalizeFundingEvidence(
        array $rawRecord,
        ImportManifest $manifest,
        Codec $codec,
        string $recordId,
        int $revision,
        \DateTimeImmutable $eventDate,
        array $origRefs,
        array $evidenceRefs,
        array $partyRoles,
        array $metadata,
        bool $isRefund,
    ): ImportRecordInput {
        $moneyRes = $this->normalizer->parseOptionalMoney($rawRecord);
        $fundingRes = $this->normalizer->parseFundingRefs($rawRecord, $isRefund);
        $requiredEvidenceKind = $isRefund ? 'refund_provider_fact' : 'payment_provider_fact';

        $missing = array_merge($moneyRes['missing'], $fundingRes['missing']);
        $reasons = array_merge($moneyRes['reasons'], $fundingRes['reasons']);
        if (! $this->normalizer->hasEvidenceKind($evidenceRefs, [$requiredEvidenceKind])) {
            $missing[] = 'evidenceRefs.'.$requiredEvidenceKind;
        }

        $targetKind = $isRefund ? ImportRecordKind::ProvenRefundEvidence : ImportRecordKind::ProvenPaymentEvidence;

        if ($moneyRes['money'] === null || $fundingRes['fundingRefs'] === null || $missing !== []) {
            if ($reasons === []) {
                $reasons[] = 'import.missing_authoritative_funding_evidence';
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
                semanticExtra: ['claimedRecordKind' => $targetKind->value],
                metadataExtra: $metadata,
            );
        }

        return $this->normalizer->buildRecordInput(
            manifest: $manifest,
            codec: $codec,
            sourceRecordId: $recordId,
            sourceRecordRevision: $revision,
            recordKind: $targetKind,
            eventBusinessDate: $eventDate,
            originalBusinessRefs: $origRefs,
            evidenceRefs: $evidenceRefs,
            money: $moneyRes['money'],
            frozenPriceSnapshot: null,
            fundingRefs: $fundingRes['fundingRefs'],
            legalDocument: null,
            missingEvidence: [],
            reasonCodes: [],
            partyRoles: $partyRoles,
            semanticExtra: [],
            metadataExtra: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @param  array<string, string>  $origRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, string>  $partyRoles
     * @param  array<string, mixed>  $metadata
     */
    private function normalizeAllocation(
        array $rawRecord,
        ImportManifest $manifest,
        Codec $codec,
        string $recordId,
        int $revision,
        \DateTimeImmutable $eventDate,
        array $origRefs,
        array $evidenceRefs,
        array $partyRoles,
        array $metadata,
    ): ImportRecordInput {
        $moneyRes = $this->normalizer->parseOptionalMoney($rawRecord);
        $missing = $moneyRes['missing'];
        $reasons = $moneyRes['reasons'];
        if (! $this->normalizer->hasEvidenceKind($evidenceRefs, ['allocation_lineage'])) {
            $missing[] = 'evidenceRefs.allocation_lineage';
        }

        if ($moneyRes['money'] === null || $missing !== []) {
            if ($reasons === []) {
                $reasons[] = 'import.missing_allocation_evidence';
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
                semanticExtra: ['claimedRecordKind' => ImportRecordKind::ProvenAllocation->value],
                metadataExtra: $metadata,
            );
        }

        return $this->normalizer->buildRecordInput(
            manifest: $manifest,
            codec: $codec,
            sourceRecordId: $recordId,
            sourceRecordRevision: $revision,
            recordKind: ImportRecordKind::ProvenAllocation,
            eventBusinessDate: $eventDate,
            originalBusinessRefs: $origRefs,
            evidenceRefs: $evidenceRefs,
            money: $moneyRes['money'],
            frozenPriceSnapshot: null,
            fundingRefs: null,
            legalDocument: null,
            missingEvidence: [],
            reasonCodes: [],
            partyRoles: $partyRoles,
            semanticExtra: [],
            metadataExtra: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @param  array<string, string>  $origRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, string>  $partyRoles
     * @param  array<string, mixed>  $metadata
     */
    private function normalizeUnanchored(
        array $rawRecord,
        ImportManifest $manifest,
        Codec $codec,
        string $recordId,
        int $revision,
        \DateTimeImmutable $eventDate,
        array $origRefs,
        array $evidenceRefs,
        array $partyRoles,
        array $metadata,
    ): ImportRecordInput {
        $missing = [];
        if (is_array($rawRecord['missingEvidence'] ?? null)) {
            foreach ($rawRecord['missingEvidence'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $missing[] = $item;
                }
            }
        }
        $reasons = [];
        if (is_array($rawRecord['reasonCodes'] ?? null)) {
            foreach ($rawRecord['reasonCodes'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $reasons[] = $item;
                }
            }
        }
        if ($missing === []) {
            $missing = ['authoritative_monetary_evidence'];
        }
        if ($reasons === []) {
            $reasons = ['import.unanchored_evidence'];
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
            semanticExtra: [],
            metadataExtra: $metadata,
        );
    }
}
