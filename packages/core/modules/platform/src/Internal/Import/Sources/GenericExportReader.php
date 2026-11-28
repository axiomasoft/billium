<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import\Sources;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Platform\Contracts\Import\ExternalImportSourceAdapter;
use Billium\Platform\Data\Import\ImportCurrencyTotal;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use DateTimeImmutable;
use JsonException;
use Override;
use Packman\Attributes\Internal;
use stdClass;

/**
 * Product-agnostic reader over immutable external export artifacts or readable local streams.
 *
 * Strictly enforces Billium self-isolation:
 * - Reads ONLY immutable local files, JSON payloads or non-network readable streams.
 * - Rejects network URIs and database DSNs (zero network/SQL access to external hosts).
 * - Verifies exportCutoff, recordsChecksum, artifactDigest, recordCount and sanitizationPolicy before normalizing.
 * - Produces ExternalImportSourceAdapter outputs with preserved original IDs and deterministic sourceFactRef.
 */
#[Internal]
final readonly class GenericExportReader implements ExternalImportSourceAdapter
{
    /** @var list<string> */
    private const array FORBIDDEN_STREAM_WRAPPERS = [
        'http',
        'https',
        'ftp',
        'ftps',
        'ssh2.shell',
        'ssh2.exec',
        'ssh2.tunnel',
        'ssh2.sftp',
        'tcp',
        'ssl',
        'tls',
        'udp',
    ];

    /**
     * @param  list<ImportRecordInput>  $normalizedRecords
     * @param  array<string, mixed>  $provenance
     */
    private function __construct(
        private ImportManifest $manifest,
        private array $normalizedRecords,
        private SourceExportProfile $profile,
        private string $rawArtifactDigest,
        private array $provenance,
        private Codec $codec,
    ) {}

    public static function fromFile(
        string $path,
        SourceExportProfile $profile,
        ?string $importPolicyVersion = null,
        ?Codec $codec = null,
    ): self {
        $trimmed = trim($path);
        if ($trimmed === '' || str_contains($trimmed, "\0")) {
            throw new ExportArtifactException('export.invalid_artifact_path', 'Export artifact path must be a bounded nonempty local path');
        }
        // Reject any URI scheme or database DSN (scheme:... or scheme://...) so the reader can only read local files.
        if (preg_match('/^[a-z][a-z0-9+.-]+:/i', $trimmed) === 1) {
            throw new ExportArtifactException(
                'export.forbidden_external_transport',
                'Export reader forbids network/DB transport URIs and DSNs; supply an immutable local artifact path or stream',
            );
        }
        if (! is_file($trimmed) || ! is_readable($trimmed)) {
            throw new ExportArtifactException('export.unreadable_artifact', "Export artifact '{$trimmed}' is missing or unreadable");
        }
        $bytes = file_get_contents($trimmed);
        if ($bytes === false) {
            throw new ExportArtifactException('export.unreadable_artifact', "Failed reading export artifact '{$trimmed}'");
        }

        return self::fromJson($bytes, $profile, $importPolicyVersion, $codec);
    }

    public static function fromStream(
        mixed $stream,
        SourceExportProfile $profile,
        ?string $importPolicyVersion = null,
        ?Codec $codec = null,
    ): self {
        if (! is_resource($stream)) {
            throw new ExportArtifactException('export.invalid_stream', 'Export reader requires an open readable stream resource');
        }
        $meta = stream_get_meta_data($stream);
        $wrapper = strtolower($meta['wrapper_type']);
        $streamType = strtolower($meta['stream_type']);
        if (in_array($wrapper, self::FORBIDDEN_STREAM_WRAPPERS, true) || str_contains($streamType, 'socket')) {
            throw new ExportArtifactException(
                'export.forbidden_external_transport',
                "Export reader forbids network stream wrapper '{$wrapper}'",
            );
        }
        if ($meta['seekable']) {
            rewind($stream);
        }
        $bytes = stream_get_contents($stream);
        if ($bytes === false) {
            throw new ExportArtifactException('export.unreadable_stream', 'Failed reading export artifact from stream');
        }

        return self::fromJson($bytes, $profile, $importPolicyVersion, $codec);
    }

    public static function fromJson(
        string $json,
        SourceExportProfile $profile,
        ?string $importPolicyVersion = null,
        ?Codec $codec = null,
    ): self {
        $codec ??= new Codec;
        $normalizer = new ExportRecordNormalizer;

        try {
            $rawObject = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $rawDecoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExportArtifactException('export.invalid_json', 'Export artifact is not valid JSON: '.$exception->getMessage());
        }
        if (! $rawObject instanceof stdClass || ! is_array($rawDecoded)) {
            throw new ExportArtifactException('export.invalid_root', 'Export artifact root must be a JSON object');
        }
        /** @var array<string, mixed> $decoded */
        $decoded = [];
        foreach ($rawDecoded as $key => $value) {
            $decoded[(string) $key] = $value;
        }

        // Fail closed on any exported source credentials anywhere in the artifact.
        $normalizer->assertNoExportedCredentials($decoded, 'artifact');

        $schema = $decoded['exportSchema'] ?? null;
        if (! is_string($schema) || $schema !== $profile->supportedSchema()) {
            throw new ExportArtifactException(
                'export.unsupported_schema',
                sprintf("Export artifact schema '%s' does not match profile supported schema '%s'", is_string($schema) ? $schema : 'null', $profile->supportedSchema()),
            );
        }

        $declaredProfile = $decoded['profile'] ?? null;
        if (is_string($declaredProfile) && $profile->profileId() !== 'generic' && $declaredProfile !== $profile->profileId()) {
            throw new ExportArtifactException(
                'export.profile_mismatch',
                "Export artifact profile '{$declaredProfile}' does not match reader profile '{$profile->profileId()}'",
            );
        }

        // Verify artifactDigest if declared (preserving nested JSON objects such as {} via stdClass tree).
        if (isset($decoded['artifactDigest'])) {
            if (! is_string($decoded['artifactDigest']) || preg_match('/^sha256:[0-9a-f]{64}$/', $decoded['artifactDigest']) !== 1) {
                throw new ExportArtifactException('export.invalid_artifact_digest', 'artifactDigest must be a valid sha256 digest');
            }
            $expectedArtifactDigest = $codec->digest($rawObject, 'artifactDigest');
            if ($decoded['artifactDigest'] !== $expectedArtifactDigest) {
                throw new ExportArtifactException(
                    'export.artifact_digest_mismatch',
                    "artifactDigest mismatch (expected {$expectedArtifactDigest}, got {$decoded['artifactDigest']})",
                );
            }
        }

        // Verify required cutoff, scope, sanitization and checksums.
        $exportCutoff = self::requireTimestamp($decoded, 'exportCutoff', $normalizer);
        $batchId = self::requireString($decoded, 'batchId');
        $installationRef = self::requireString($decoded, 'installationRef');
        $environment = self::requireString($decoded, 'environment');
        $sellerRef = self::requireString($decoded, 'sellerRef');
        $sourceSystem = self::requireString($decoded, 'sourceSystem');
        $sourceInstallation = self::requireString($decoded, 'sourceInstallation');
        $sourceBook = self::requireString($decoded, 'sourceBook');

        $sourceExportRevision = $decoded['sourceExportRevision'] ?? null;
        if (! is_int($sourceExportRevision) || $sourceExportRevision < 0) {
            throw new ExportArtifactException('export.invalid_revision', 'sourceExportRevision must be a non-negative integer');
        }

        $sanitization = self::requireSanitizationPolicy($decoded);
        $rawRecords = $decoded['records'] ?? null;
        $rawRecordObjects = $rawObject->records ?? null;
        if (! is_array($rawRecords) || ! array_is_list($rawRecords) || ! is_array($rawRecordObjects) || ! array_is_list($rawRecordObjects)) {
            throw new ExportArtifactException('export.invalid_records', 'Export artifact requires a list of records');
        }

        $declaredCount = $decoded['recordCount'] ?? null;
        if (! is_int($declaredCount) || $declaredCount !== count($rawRecords)) {
            throw new ExportArtifactException(
                'export.record_count_mismatch',
                sprintf('Declared recordCount does not match actual record count (%d)', count($rawRecords)),
            );
        }

        $declaredChecksum = $decoded['recordsChecksum'] ?? null;
        if (! is_string($declaredChecksum) || preg_match('/^sha256:[0-9a-f]{64}$/', $declaredChecksum) !== 1) {
            throw new ExportArtifactException('export.missing_records_checksum', 'Export artifact requires a valid sha256 recordsChecksum');
        }
        $actualChecksum = 'sha256:'.hash('sha256', $codec->canonicalize($rawRecordObjects));
        if ($declaredChecksum !== $actualChecksum) {
            throw new ExportArtifactException(
                'export.records_checksum_mismatch',
                "recordsChecksum mismatch (expected {$actualChecksum}, got {$declaredChecksum})",
            );
        }

        $totalsByCurrency = self::parseDeclaredTotals($decoded['totalsByCurrency'] ?? [], $sellerRef);
        $effectivePolicyVersion = $importPolicyVersion
            ?? (is_string($decoded['importPolicyVersion'] ?? null) && trim($decoded['importPolicyVersion']) !== '' ? $decoded['importPolicyVersion'] : null);
        $sourceLineage = is_string($decoded['sourceLineage'] ?? null) && trim($decoded['sourceLineage']) !== '' ? $decoded['sourceLineage'] : null;
        $freezeCertRef = is_string($decoded['sourceFreezeCertificateRef'] ?? null) && trim($decoded['sourceFreezeCertificateRef']) !== '' ? $decoded['sourceFreezeCertificateRef'] : null;

        // Build manifest wire body (without digest) to compute deterministic external.import.manifest.v1 digest.
        $manifestBody = [
            'schema' => 'external.import.manifest.v1',
            'batchId' => $batchId,
            'sourceSystem' => $sourceSystem,
            'sourceInstallation' => $sourceInstallation,
            'sourceBook' => $sourceBook,
            'sourceExportRevision' => $sourceExportRevision,
            'exportCutoff' => $exportCutoff->format('Y-m-d\TH:i:s\Z'),
            'installationRef' => $installationRef,
            'environment' => $environment,
            'sellerRef' => $sellerRef,
            'recordCount' => count($rawRecords),
            'recordsChecksum' => $declaredChecksum,
            'totalsByCurrency' => array_map(static fn (ImportCurrencyTotal $t): array => $t->toWire(), $totalsByCurrency),
            'sanitizationPolicy' => $sanitization,
        ];
        if ($sourceLineage !== null) {
            $manifestBody['sourceLineage'] = $sourceLineage;
        }
        if ($effectivePolicyVersion !== null) {
            $manifestBody['importPolicyVersion'] = $effectivePolicyVersion;
        }
        if ($freezeCertRef !== null) {
            $manifestBody['sourceFreezeCertificateRef'] = $freezeCertRef;
        }
        $manifestDigest = $codec->digest($manifestBody);

        $provenance = is_array($decoded['provenance'] ?? null) ? $decoded['provenance'] : [];
        /** @var array<string, mixed> $typedProvenance */
        $typedProvenance = [];
        foreach ($provenance as $k => $v) {
            $typedProvenance[(string) $k] = $v;
        }

        $manifest = new ImportManifest(
            batchId: $batchId,
            installationRef: $installationRef,
            environment: $environment,
            sellerRef: $sellerRef,
            sourceSystem: $sourceSystem,
            sourceInstallation: $sourceInstallation,
            sourceBook: $sourceBook,
            sourceExportRevision: $sourceExportRevision,
            exportCutoff: $exportCutoff,
            recordCount: count($rawRecords),
            recordsChecksum: $declaredChecksum,
            manifestDigest: $manifestDigest,
            sanitizationPolicy: $sanitization,
            totalsByCurrency: $totalsByCurrency,
            sourceLineage: $sourceLineage,
            importPolicyVersion: $effectivePolicyVersion,
            sourceFreezeCertificateRef: $freezeCertRef,
            rawPayload: ['provenance' => $typedProvenance, 'exportSchema' => $schema],
        );

        $normalized = [];
        foreach ($rawRecords as $idx => $rawRecord) {
            $rawRecordObject = $rawRecordObjects[$idx] ?? null;
            if (! $rawRecordObject instanceof stdClass || ! is_array($rawRecord)) {
                throw new ExportArtifactException('export.invalid_record', "Record at index {$idx} must be a JSON object");
            }
            /** @var array<string, mixed> $typedRecord */
            $typedRecord = $rawRecord;
            $normalizedItem = $profile->normalizeRecord($typedRecord, $manifest, $codec);
            $payloadWithMeta = $normalizedItem->payload;
            $existingMeta = is_array($payloadWithMeta['mapperMetadata'] ?? null) ? $payloadWithMeta['mapperMetadata'] : [];
            $existingMeta['sourceRecordIndex'] = $idx;
            $existingMeta['sourceRecordCanonical'] = $codec->canonicalize($rawRecordObject);
            $payloadWithMeta['mapperMetadata'] = $existingMeta;
            $normalized[] = new ImportRecordInput(
                identity: $normalizedItem->identity,
                batchId: $normalizedItem->batchId,
                sourceExportRevision: $normalizedItem->sourceExportRevision,
                exportCutoff: $normalizedItem->exportCutoff,
                manifestDigest: $normalizedItem->manifestDigest,
                sourceRecordRevision: $normalizedItem->sourceRecordRevision,
                eventBusinessDate: $normalizedItem->eventBusinessDate,
                payloadDigest: $normalizedItem->payloadDigest,
                originalBusinessRefs: $normalizedItem->originalBusinessRefs,
                evidenceRefs: $normalizedItem->evidenceRefs,
                money: $normalizedItem->money,
                frozenPriceSnapshot: $normalizedItem->frozenPriceSnapshot,
                fundingRefs: $normalizedItem->fundingRefs,
                legalDocument: $normalizedItem->legalDocument,
                importPolicyVersion: $normalizedItem->importPolicyVersion,
                sourceFactRef: $normalizedItem->sourceFactRef,
                missingEvidence: $normalizedItem->missingEvidence,
                payload: $payloadWithMeta,
            );
        }

        return new self(
            manifest: $manifest,
            normalizedRecords: $normalized,
            profile: $profile,
            rawArtifactDigest: 'sha256:'.hash('sha256', $json),
            provenance: $typedProvenance,
            codec: $codec,
        );
    }

    #[Override]
    public function manifest(): ImportManifest
    {
        return $this->manifest;
    }

    /**
     * @return list<ImportRecordInput>
     */
    #[Override]
    public function records(): array
    {
        return $this->normalizedRecords;
    }

    public function profile(): SourceExportProfile
    {
        return $this->profile;
    }

    public function artifactDigest(): string
    {
        return $this->rawArtifactDigest;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /**
     * Builds a deterministic, checksummed source-mapping manifest matching check-import-contract.py --source-mappings.
     *
     * @return array<string, mixed>
     */
    public function buildSourceMappingSummary(): array
    {
        $mappings = [];
        $entryDigests = [];
        foreach ($this->normalizedRecords as $record) {
            $id = $record->identity;
            $identityDigest = $id->identityDigest($this->codec);
            $factRef = $record->sourceFactRef ?? $id->stableSourceFactRef($record->sourceRecordRevision, $this->codec);
            $mappings[] = [
                'installationRef' => $id->installationRef,
                'environment' => $id->environment,
                'sellerRef' => $id->sellerRef,
                'sourceSystem' => $id->sourceSystem,
                'sourceInstallation' => $id->sourceInstallation,
                'sourceBook' => $id->sourceBook,
                'sourceRecordId' => $id->sourceRecordId,
                'recordKind' => $id->recordKind->value,
                'sourceRecordRevision' => $record->sourceRecordRevision,
                'sourceIdentityDigest' => $identityDigest,
                'payloadDigest' => $record->payloadDigest,
                'sourceFactRef' => $factRef,
                'status' => 'active',
            ];
            $entryDigests[] = $identityDigest.':'.$record->sourceRecordRevision.':'.$record->payloadDigest;
        }
        sort($entryDigests, SORT_STRING);
        $checksum = 'sha256:'.hash('sha256', $this->codec->canonicalize($entryDigests));

        return [
            'namespace' => $this->manifest->sourceSystem,
            'batchIdInEconomicIdentity' => false,
            'renamesSourceBookOnMapperUpdate' => false,
            'mappingsChecksum' => $checksum,
            'mappings' => $mappings,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function requireString(array $decoded, string $field): string
    {
        $val = $decoded[$field] ?? null;
        if (! is_string($val) || trim($val) === '' || strlen($val) > 200 || str_contains($val, "\0")) {
            throw new ExportArtifactException("export.missing_{$field}", "Export artifact requires bounded nonempty string '{$field}'");
        }

        return $val;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function requireTimestamp(array $decoded, string $field, ExportRecordNormalizer $normalizer): DateTimeImmutable
    {
        $val = $decoded[$field] ?? null;
        if (! is_string($val)) {
            throw new ExportArtifactException("export.missing_{$field}", "Export artifact requires RFC3339 UTC timestamp '{$field}'");
        }
        $dt = $normalizer->parseUtcTimestamp($val);
        if ($dt === null) {
            throw new ExportArtifactException("export.invalid_{$field}", "Export artifact '{$field}' must be a valid RFC3339 UTC timestamp");
        }

        return $dt;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{piiRedacted: bool, secretsStripped: bool, policyVersion: string}
     */
    private static function requireSanitizationPolicy(array $decoded): array
    {
        $raw = $decoded['sanitizationPolicy'] ?? null;
        if (! is_array($raw)) {
            throw new ExportArtifactException('export.missing_sanitization_policy', 'Export artifact requires explicit sanitizationPolicy');
        }
        $pii = $raw['piiRedacted'] ?? null;
        $secrets = $raw['secretsStripped'] ?? null;
        $version = $raw['policyVersion'] ?? null;
        if ($pii !== true || $secrets !== true || ! is_string($version) || trim($version) === '') {
            throw new ExportArtifactException(
                'export.unsanitized_artifact',
                'Export artifact must declare sanitizationPolicy with piiRedacted=true, secretsStripped=true and nonempty policyVersion',
            );
        }

        return [
            'piiRedacted' => true,
            'secretsStripped' => true,
            'policyVersion' => $version,
        ];
    }

    /**
     * @return list<ImportCurrencyTotal>
     */
    private static function parseDeclaredTotals(mixed $rawTotals, string $sellerRef): array
    {
        if (! is_array($rawTotals)) {
            throw new ExportArtifactException('export.invalid_totals', 'totalsByCurrency must be a list');
        }
        $totals = [];
        foreach ($rawTotals as $idx => $entry) {
            if (! is_array($entry)) {
                throw new ExportArtifactException('export.invalid_totals', "totalsByCurrency[{$idx}] must be an object");
            }
            $entrySeller = $entry['sellerRef'] ?? null;
            $currency = $entry['currency'] ?? null;
            $kindRaw = $entry['recordKind'] ?? null;
            $count = $entry['count'] ?? null;
            $authCurrent = $entry['authoritativeCurrentBalance'] ?? null;
            if ($entrySeller !== $sellerRef) {
                throw new ExportArtifactException('export.cross_seller_total', "totalsByCurrency[{$idx}] sellerRef does not match manifest sellerRef");
            }
            if ($authCurrent !== false) {
                throw new ExportArtifactException('export.authoritative_balance_claimed', "totalsByCurrency[{$idx}] must set authoritativeCurrentBalance=false");
            }
            $kind = is_string($kindRaw) ? ImportRecordKind::tryFrom($kindRaw) : null;
            if (! is_string($currency) || $kind === null || ! is_int($count) || $count < 0) {
                throw new ExportArtifactException('export.invalid_totals', "totalsByCurrency[{$idx}] has invalid currency, recordKind or count");
            }
            try {
                $money = Money::fromWire($entry['totalAmount'] ?? null);
            } catch (ProblemException $exception) {
                throw new ExportArtifactException('export.invalid_total_money', "totalsByCurrency[{$idx}] totalAmount invalid: ".$exception->getMessage());
            }
            $totals[] = new ImportCurrencyTotal(
                sellerRef: $sellerRef,
                currency: $currency,
                recordKind: $kind,
                count: $count,
                totalAmount: $money,
                authoritativeCurrentBalance: false,
            );
        }

        return $totals;
    }
}
