<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import\Sources;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Data\Import\ImportEvidenceRef;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;
use Throwable;

/**
 * Shared fail-closed normalizer for product-agnostic source export records.
 *
 * Guarantees:
 * - Never fabricates 0, default currency, current plan price, document display price, today, captured status or synthetic provider refs.
 * - Rejects raw source credentials anywhere in exported records so credentials never become monetary authority.
 * - Preserves original external IDs and party role separation without conflating subject/payer/actor/customer with seller.
 * - Computes canonical payloadDigest independent of batchId, filename, sourceExportRevision and mapper policy/profile version.
 */
#[Internal]
final readonly class ExportRecordNormalizer
{
    /** @var list<string> */
    private const array FORBIDDEN_CREDENTIAL_KEYS = [
        'apikey',
        'apisecret',
        'secretkey',
        'providersecret',
        'pspcredential',
        'sourcecredential',
        'oauthtoken',
        'accesstoken',
        'refreshtoken',
        'privatekey',
        'password',
        'clientsecret',
        'webhooksecret',
        'bearertoken',
        'signingsecret',
    ];

    /** @var list<string> */
    private const array ALLOWED_BUSINESS_REF_KEYS = [
        'customerRef',
        'billingAccountRef',
        'agreementRef',
        'subscriptionRef',
        'orderRef',
        'obligationRef',
        'invoiceRef',
        'paymentRef',
        'originalPaymentRef',
        'correctionOfRecordId',
        'originalIssuer',
        'sourceNumber',
    ];

    /** @var list<string> */
    private const array ALLOWED_EVIDENCE_KINDS = [
        'price_snapshot',
        'tax_snapshot',
        'invoice_document',
        'payment_provider_fact',
        'refund_provider_fact',
        'allocation_lineage',
        'agreement_reference',
        'source_manifest',
        'authority_fence',
    ];

    /** @var list<string> */
    private const array ALLOWED_FUNDING_STATUSES = [
        'authorized',
        'captured',
        'refunded',
        'failed_terminal',
        'cancelled',
        'unknown',
    ];

    /** @var list<string> */
    private const array UNTRUSTED_PRICE_BASES = [
        'current_plan',
        'document_display_price',
        'inferred',
        'estimated',
        'mutable_catalog',
    ];

    /**
     * Fails closed if any secret/credential field appears anywhere in the artifact or record tree.
     *
     * @param  array<mixed>  $data
     */
    public function assertNoExportedCredentials(array $data, string $path = 'root'): void
    {
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            $normalizedKey = strtolower(str_replace(['_', '-', ' '], '', $keyStr));
            if (in_array($normalizedKey, self::FORBIDDEN_CREDENTIAL_KEYS, true)) {
                throw new ExportArtifactException(
                    'export.forbidden_source_credential',
                    "Exported source credential key '{$path}.{$keyStr}' is forbidden and cannot become monetary authority",
                );
            }
            if (is_array($value)) {
                $this->assertNoExportedCredentials($value, $path.'.'.$keyStr);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     */
    public function requireRecordId(array $rawRecord): string
    {
        $id = $rawRecord['sourceRecordId'] ?? null;
        if (! is_string($id) || trim($id) === '' || strlen($id) > 200 || str_contains($id, "\0")) {
            throw new ExportArtifactException('export.missing_source_record_id', 'Export record requires a bounded nonempty sourceRecordId');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     */
    public function requireRecordRevision(array $rawRecord): int
    {
        $rev = $rawRecord['sourceRecordRevision'] ?? null;
        if (! is_int($rev) || $rev < 0) {
            throw new ExportArtifactException('export.invalid_source_record_revision', 'Export record requires a non-negative integer sourceRecordRevision');
        }

        return $rev;
    }

    /**
     * Parses eventBusinessDate strictly; never substitutes "today" when absent.
     *
     * @param  array<string, mixed>  $rawRecord
     */
    public function parseEventBusinessDate(array $rawRecord): ?DateTimeImmutable
    {
        $raw = $rawRecord['eventBusinessDate'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $this->parseUtcTimestamp($raw);
    }

    public function parseUtcTimestamp(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', $value) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extracts schema-allowed original business references without renaming.
     *
     * @param  array<string, mixed>  $rawRecord
     * @return array<string, string>
     */
    public function extractOriginalBusinessRefs(array $rawRecord): array
    {
        $rawRefs = $rawRecord['originalBusinessRefs'] ?? [];
        if (! is_array($rawRefs)) {
            return [];
        }
        $refs = [];
        foreach (self::ALLOWED_BUSINESS_REF_KEYS as $key) {
            $val = $rawRefs[$key] ?? null;
            if (is_string($val) && trim($val) !== '' && strlen($val) <= 200 && ! str_contains($val, "\0")) {
                $refs[$key] = $val;
            }
        }

        return $refs;
    }

    /**
     * Distinguishes subject/payer/actor/customer from seller without conflating them.
     *
     * @param  array<string, mixed>  $rawRecord
     * @return array<string, string>
     */
    public function extractPartyRoles(array $rawRecord, ImportManifest $manifest): array
    {
        $roles = [
            'sellerRef' => $manifest->sellerRef,
            'sourceSystem' => $manifest->sourceSystem,
        ];

        $rawParties = $rawRecord['partyRoles'] ?? [];
        if (is_array($rawParties)) {
            foreach (['subjectRef', 'payerRef', 'actorRef', 'customerRef', 'billingAccountRef'] as $role) {
                $val = $rawParties[$role] ?? null;
                if (is_string($val) && trim($val) !== '' && strlen($val) <= 200 && ! str_contains($val, "\0")) {
                    $roles[$role] = $val;
                }
            }
        }

        $origRefs = $this->extractOriginalBusinessRefs($rawRecord);
        if (! isset($roles['customerRef']) && isset($origRefs['customerRef'])) {
            $roles['customerRef'] = $origRefs['customerRef'];
        }
        if (! isset($roles['billingAccountRef']) && isset($origRefs['billingAccountRef'])) {
            $roles['billingAccountRef'] = $origRefs['billingAccountRef'];
        }

        return $roles;
    }

    /**
     * @param  array<string, mixed>  $rawRecord
     * @return list<ImportEvidenceRef>
     */
    public function parseEvidenceRefs(array $rawRecord): array
    {
        $rawList = $rawRecord['evidenceRefs'] ?? [];
        if (! is_array($rawList)) {
            return [];
        }
        $refs = [];
        foreach ($rawList as $item) {
            if (! is_array($item)) {
                continue;
            }
            $evidenceRef = $item['evidenceRef'] ?? null;
            $kind = $item['kind'] ?? null;
            $digest = $item['digest'] ?? null;
            $sanitized = $item['sanitized'] ?? null;
            if (
                ! is_string($evidenceRef) || trim($evidenceRef) === ''
                || ! is_string($kind) || ! in_array($kind, self::ALLOWED_EVIDENCE_KINDS, true)
                || ! is_string($digest) || preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1
                || ! is_bool($sanitized)
            ) {
                continue;
            }
            if (! $sanitized) {
                throw new ExportArtifactException(
                    'export.unsanitized_evidence_ref',
                    "Evidence reference '{$evidenceRef}' is not marked sanitized",
                );
            }
            $capturedAt = is_string($item['capturedAt'] ?? null) ? $this->parseUtcTimestamp($item['capturedAt']) : null;
            $sourceUri = is_string($item['sourceUri'] ?? null) && trim($item['sourceUri']) !== '' ? $item['sourceUri'] : null;
            $refs[] = new ImportEvidenceRef(
                evidenceRef: $evidenceRef,
                kind: $kind,
                digest: $digest,
                sanitized: true,
                capturedAt: $capturedAt,
                sourceUri: $sourceUri,
            );
        }

        return $refs;
    }

    /**
     * Parses Money strictly from string amount + valid ISO 4217 currency.
     * Returns ['money' => ?Money, 'missing' => list<string>, 'reasons' => list<string>].
     *
     * @param  array<string, mixed>  $rawRecord
     * @return array{money: ?Money, missing: list<string>, reasons: list<string>}
     */
    public function parseOptionalMoney(array $rawRecord): array
    {
        if (! array_key_exists('money', $rawRecord) || $rawRecord['money'] === null) {
            return ['money' => null, 'missing' => ['money'], 'reasons' => []];
        }
        $rawMoney = $rawRecord['money'];
        if (! is_array($rawMoney)) {
            return ['money' => null, 'missing' => ['money'], 'reasons' => ['import.invalid_money_shape']];
        }

        $missing = [];
        $reasons = [];
        $amount = $rawMoney['amount'] ?? null;
        $currency = $rawMoney['currency'] ?? null;

        if (! is_string($amount) || trim($amount) === '') {
            $missing[] = 'money.amount';
        }
        if (! is_string($currency) || trim($currency) === '') {
            $missing[] = 'currency';
        }
        if ($missing !== []) {
            return ['money' => null, 'missing' => $missing, 'reasons' => $reasons];
        }

        assert(is_string($amount) && is_string($currency));
        try {
            Currency::of($currency);
        } catch (ProblemException) {
            return [
                'money' => null,
                'missing' => ['iso4217_currency'],
                'reasons' => ['import.noncurrency_unit_rejected'],
            ];
        }

        try {
            $money = Money::parse($amount, $currency);
        } catch (ProblemException) {
            return [
                'money' => null,
                'missing' => ['money'],
                'reasons' => ['import.invalid_money_scale'],
            ];
        }

        return ['money' => $money, 'missing' => [], 'reasons' => []];
    }

    /**
     * Validates a proven historical frozen price snapshot.
     * Never accepts current plan or document display prices as a frozen PriceVersion.
     *
     * @param  array<string, mixed>  $rawRecord
     * @return array{snapshot: ?array<string, mixed>, missing: list<string>, reasons: list<string>}
     */
    public function parseFrozenPriceSnapshot(array $rawRecord): array
    {
        $raw = $rawRecord['frozenPriceSnapshot'] ?? null;
        if (! is_array($raw)) {
            $reasons = [];
            if (isset($rawRecord['currentPlanSnapshot']) || isset($rawRecord['documentDisplayPrice'])) {
                $reasons[] = 'import.current_plan_cannot_anchor_history';
            }

            return [
                'snapshot' => null,
                'missing' => ['frozenPriceSnapshot'],
                'reasons' => $reasons,
            ];
        }

        $basis = $raw['basis'] ?? 'frozen_historical';
        if (is_string($basis) && in_array($basis, self::UNTRUSTED_PRICE_BASES, true)) {
            return [
                'snapshot' => null,
                'missing' => ['frozenPriceSnapshot'],
                'reasons' => ['import.current_plan_cannot_anchor_history'],
            ];
        }

        $priceVersionRef = $raw['priceVersionRef'] ?? null;
        $priceDigest = $raw['priceDigest'] ?? null;
        $currency = $raw['currency'] ?? null;

        $missing = [];
        if (! is_string($priceVersionRef) || trim($priceVersionRef) === '') {
            $missing[] = 'frozenPriceSnapshot.priceVersionRef';
        }
        if (! is_string($priceDigest) || preg_match('/^sha256:[0-9a-f]{64}$/', $priceDigest) !== 1) {
            $missing[] = 'frozenPriceSnapshot.priceDigest';
        }
        if (! is_string($currency) || trim($currency) === '') {
            $missing[] = 'frozenPriceSnapshot.currency';
        } else {
            try {
                Currency::of($currency);
            } catch (ProblemException) {
                $missing[] = 'frozenPriceSnapshot.currency';
            }
        }
        if ($missing !== []) {
            return ['snapshot' => null, 'missing' => $missing, 'reasons' => ['import.missing_proven_price_basis']];
        }

        assert(is_string($priceVersionRef) && is_string($priceDigest) && is_string($currency));
        $snapshot = [
            'priceVersionRef' => $priceVersionRef,
            'priceDigest' => $priceDigest,
            'currency' => $currency,
        ];
        if (is_string($raw['termsRef'] ?? null) && trim($raw['termsRef']) !== '') {
            $snapshot['termsRef'] = $raw['termsRef'];
        }
        if (is_string($raw['taxPolicyVersion'] ?? null) && trim($raw['taxPolicyVersion']) !== '') {
            $snapshot['taxPolicyVersion'] = $raw['taxPolicyVersion'];
        }
        if (is_string($raw['taxSnapshotDigest'] ?? null) && preg_match('/^sha256:[0-9a-f]{64}$/', $raw['taxSnapshotDigest']) === 1) {
            $snapshot['taxSnapshotDigest'] = $raw['taxSnapshotDigest'];
        }
        if (is_array($raw['unitPrice'] ?? null)) {
            try {
                $snapshot['unitPrice'] = Money::fromWire($raw['unitPrice'])->toWire();
            } catch (ProblemException) {
                return ['snapshot' => null, 'missing' => ['frozenPriceSnapshot.unitPrice'], 'reasons' => ['import.invalid_money_scale']];
            }
        }
        if (is_string($raw['effectiveAt'] ?? null) && $this->parseUtcTimestamp($raw['effectiveAt']) !== null) {
            $snapshot['effectiveAt'] = $raw['effectiveAt'];
        }

        return ['snapshot' => $snapshot, 'missing' => [], 'reasons' => []];
    }

    /**
     * Parses authoritative funding references without inventing provider IDs or captured status.
     *
     * @param  array<string, mixed>  $rawRecord
     * @return array{fundingRefs: ?array<string, mixed>, missing: list<string>, reasons: list<string>}
     */
    public function parseFundingRefs(array $rawRecord, bool $requireOriginalOperation): array
    {
        $raw = $rawRecord['fundingRefs'] ?? null;
        if (! is_array($raw)) {
            $missing = ['fundingRefs', 'fundingRefs.providerOperationRef'];
            if ($requireOriginalOperation) {
                $missing[] = 'fundingRefs.originalOperationRef';
            }

            return ['fundingRefs' => null, 'missing' => $missing, 'reasons' => ['import.missing_authoritative_funding_evidence']];
        }

        $missing = [];
        $reasons = [];
        $sourceAuthority = $raw['sourceAuthority'] ?? null;
        $fundingStatus = $raw['fundingStatus'] ?? null;
        $providerOp = $raw['providerOperationRef'] ?? null;
        $originalOp = $raw['originalOperationRef'] ?? null;

        if (! is_string($sourceAuthority) || trim($sourceAuthority) === '') {
            $missing[] = 'fundingRefs.sourceAuthority';
        }
        if (! is_string($fundingStatus) || ! in_array($fundingStatus, self::ALLOWED_FUNDING_STATUSES, true)) {
            $missing[] = 'fundingRefs.fundingStatus';
        }
        if ($fundingStatus === 'unknown') {
            $missing[] = 'fundingRefs.resolvedFundingStatus';
            $reasons[] = 'import.unresolved_remote_funding_status';
        }
        if (! is_string($providerOp) || trim($providerOp) === '') {
            $missing[] = 'fundingRefs.providerOperationRef';
        }
        if ($requireOriginalOperation && (! is_string($originalOp) || trim($originalOp) === '')) {
            $missing[] = 'fundingRefs.originalOperationRef';
        }

        if ($missing !== []) {
            if ($reasons === []) {
                $reasons[] = 'import.missing_authoritative_funding_evidence';
            }

            return ['fundingRefs' => null, 'missing' => $missing, 'reasons' => $reasons];
        }

        assert(is_string($sourceAuthority) && is_string($fundingStatus) && is_string($providerOp));
        $refs = [
            'sourceAuthority' => $sourceAuthority,
            'fundingStatus' => $fundingStatus,
            'providerOperationRef' => $providerOp,
        ];
        foreach (['providerAccountRef', 'originalOperationRef', 'originalExecutorRef'] as $opt) {
            if (is_string($raw[$opt] ?? null) && trim($raw[$opt]) !== '') {
                $refs[$opt] = $raw[$opt];
            }
        }
        if (is_string($raw['observedAt'] ?? null) && $this->parseUtcTimestamp($raw['observedAt']) !== null) {
            $refs['observedAt'] = $raw['observedAt'];
        }

        return ['fundingRefs' => $refs, 'missing' => [], 'reasons' => []];
    }

    /**
     * Preserves historical invoice numbering without issuing a second Billium legal number (IM-16).
     *
     * @param  array<string, mixed>  $rawRecord
     * @return array{legalDocument: ?array<string, mixed>, missing: list<string>, reasons: list<string>}
     */
    public function parseHistoricalLegalDocument(array $rawRecord): array
    {
        $raw = $rawRecord['legalDocument'] ?? null;
        if (! is_array($raw)) {
            return [
                'legalDocument' => null,
                'missing' => ['legalDocument.originalIssuer', 'legalDocument.sourceNumber'],
                'reasons' => ['import.missing_historical_legal_number'],
            ];
        }

        $missing = [];
        $originalIssuer = $raw['originalIssuer'] ?? null;
        $sourceNumber = $raw['sourceNumber'] ?? null;
        $confirmed = $raw['legalOwnershipConfirmed'] ?? null;

        if (! is_string($originalIssuer) || trim($originalIssuer) === '') {
            $missing[] = 'legalDocument.originalIssuer';
        }
        if (! is_string($sourceNumber) || trim($sourceNumber) === '') {
            $missing[] = 'legalDocument.sourceNumber';
        }
        if (! is_bool($confirmed)) {
            $missing[] = 'legalDocument.legalOwnershipConfirmed';
        }
        if ($missing !== []) {
            return [
                'legalDocument' => null,
                'missing' => $missing,
                'reasons' => ['import.missing_historical_legal_number'],
            ];
        }

        assert(is_string($originalIssuer) && is_string($sourceNumber) && is_bool($confirmed));
        $doc = [
            'originalIssuer' => $originalIssuer,
            'sourceNumber' => $sourceNumber,
            'legalOwnershipConfirmed' => $confirmed,
        ];
        if (is_string($raw['issuedAt'] ?? null) && $this->parseUtcTimestamp($raw['issuedAt']) !== null) {
            $doc['issuedAt'] = $raw['issuedAt'];
        }
        if (is_string($raw['renderDigest'] ?? null) && preg_match('/^sha256:[0-9a-f]{64}$/', $raw['renderDigest']) === 1) {
            $doc['renderDigest'] = $raw['renderDigest'];
        }

        return ['legalDocument' => $doc, 'missing' => [], 'reasons' => []];
    }

    /**
     * Builds an ImportRecordInput with canonical payloadDigest and stable sourceFactRef.
     *
     * Notice: batchId, sourceExportRevision, importPolicyVersion, profileId and profileVersion are intentionally
     * excluded from semanticPayload and from sourceIdentityDigest/sourceFactRef so mapper/policy upgrades and
     * batch repackaging never change economic identity.
     *
     * @param  array<string, string>  $originalBusinessRefs
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  array<string, mixed>|null  $frozenPriceSnapshot
     * @param  array<string, mixed>|null  $fundingRefs
     * @param  array<string, mixed>|null  $legalDocument
     * @param  list<string>  $missingEvidence
     * @param  list<string>  $reasonCodes
     * @param  array<string, string>  $partyRoles
     * @param  array<string, mixed>  $semanticExtra
     * @param  array<string, mixed>  $metadataExtra
     */
    public function buildRecordInput(
        ImportManifest $manifest,
        Codec $codec,
        string $sourceRecordId,
        int $sourceRecordRevision,
        ImportRecordKind $recordKind,
        DateTimeImmutable $eventBusinessDate,
        array $originalBusinessRefs,
        array $evidenceRefs,
        ?Money $money,
        ?array $frozenPriceSnapshot,
        ?array $fundingRefs,
        ?array $legalDocument,
        array $missingEvidence,
        array $reasonCodes,
        array $partyRoles,
        array $semanticExtra = [],
        array $metadataExtra = [],
    ): ImportRecordInput {
        $identity = new ImportSourceIdentity(
            installationRef: $manifest->installationRef,
            environment: $manifest->environment,
            sellerRef: $manifest->sellerRef,
            sourceSystem: $manifest->sourceSystem,
            sourceInstallation: $manifest->sourceInstallation,
            sourceBook: $manifest->sourceBook,
            sourceRecordId: $sourceRecordId,
            recordKind: $recordKind,
        );

        $normalizedMissing = array_values(array_unique($missingEvidence));
        $normalizedReasons = array_values(array_unique($reasonCodes));
        if ($recordKind === ImportRecordKind::UnanchoredEvidence) {
            if ($normalizedMissing === []) {
                $normalizedMissing = ['authoritative_monetary_evidence'];
            }
            if ($normalizedReasons === []) {
                $normalizedReasons = ['import.unanchored_evidence'];
            }
        }

        $semanticPayload = [
            'installationRef' => $identity->installationRef,
            'environment' => $identity->environment,
            'sellerRef' => $identity->sellerRef,
            'sourceInstallation' => $identity->sourceInstallation,
            'sourceBook' => $identity->sourceBook,
            'sourceRecordId' => $identity->sourceRecordId,
            'sourceRecordRevision' => $sourceRecordRevision,
            'recordKind' => $recordKind->value,
            'eventBusinessDate' => $eventBusinessDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'originalBusinessRefs' => $originalBusinessRefs,
            'evidenceRefs' => array_map(static fn (ImportEvidenceRef $e): array => $e->toWire(), $evidenceRefs),
            'partyRoles' => $partyRoles,
        ];
        if ($money !== null) {
            $semanticPayload['money'] = $money->toWire();
        }
        if ($frozenPriceSnapshot !== null) {
            $semanticPayload['frozenPriceSnapshot'] = $frozenPriceSnapshot;
        }
        if ($fundingRefs !== null) {
            $semanticPayload['fundingRefs'] = $fundingRefs;
        }
        if ($legalDocument !== null) {
            $semanticPayload['legalDocument'] = $legalDocument;
        }
        if ($normalizedMissing !== []) {
            $semanticPayload['missingEvidence'] = $normalizedMissing;
        }
        if ($normalizedReasons !== []) {
            $semanticPayload['unanchoredReasonCodes'] = $normalizedReasons;
        }
        if ($semanticExtra !== []) {
            $semanticPayload['provenanceDetails'] = $semanticExtra;
        }

        $payloadDigest = 'sha256:'.hash('sha256', $codec->canonicalize($semanticPayload));
        $sourceFactRef = $identity->stableSourceFactRef($sourceRecordRevision, $codec);

        $fullPayload = $semanticPayload;
        if ($metadataExtra !== []) {
            $fullPayload['mapperMetadata'] = $metadataExtra;
        }

        return new ImportRecordInput(
            identity: $identity,
            batchId: $manifest->batchId,
            sourceExportRevision: $manifest->sourceExportRevision,
            exportCutoff: $manifest->exportCutoff,
            manifestDigest: $manifest->manifestDigest,
            sourceRecordRevision: $sourceRecordRevision,
            eventBusinessDate: $eventBusinessDate,
            payloadDigest: $payloadDigest,
            originalBusinessRefs: $originalBusinessRefs,
            evidenceRefs: $evidenceRefs,
            money: $money,
            frozenPriceSnapshot: $frozenPriceSnapshot,
            fundingRefs: $fundingRefs,
            legalDocument: $legalDocument,
            importPolicyVersion: $manifest->importPolicyVersion,
            sourceFactRef: $sourceFactRef,
            missingEvidence: $normalizedMissing,
            payload: $fullPayload,
        );
    }

    /**
     * Checks whether at least one evidence item of any required kind is attached.
     *
     * @param  list<ImportEvidenceRef>  $evidenceRefs
     * @param  list<string>  $kinds
     */
    public function hasEvidenceKind(array $evidenceRefs, array $kinds): bool
    {
        foreach ($evidenceRefs as $ref) {
            if (in_array($ref->kind, $kinds, true)) {
                return true;
            }
        }

        return false;
    }
}
