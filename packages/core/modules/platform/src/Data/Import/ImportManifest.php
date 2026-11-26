<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Immutable evidence manifest describing one external export batch with seller/environment scope.
 */
#[Api(since: '1.0')]
final readonly class ImportManifest
{
    /**
     * @param  array{piiRedacted: bool, secretsStripped: bool, policyVersion: string}  $sanitizationPolicy
     * @param  list<ImportCurrencyTotal>  $totalsByCurrency
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $batchId,
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $sourceSystem,
        public string $sourceInstallation,
        public string $sourceBook,
        public int $sourceExportRevision,
        public DateTimeImmutable $exportCutoff,
        public int $recordCount,
        public string $recordsChecksum,
        public string $manifestDigest,
        public array $sanitizationPolicy,
        public array $totalsByCurrency = [],
        public ?string $sourceLineage = null,
        public ?string $importPolicyVersion = null,
        public ?string $sourceFreezeCertificateRef = null,
        public array $rawPayload = [],
    ) {
        foreach ([
            'batchId' => $batchId,
            'installationRef' => $installationRef,
            'environment' => $environment,
            'sellerRef' => $sellerRef,
            'sourceInstallation' => $sourceInstallation,
            'sourceBook' => $sourceBook,
        ] as $field => $value) {
            if (trim($value) === '' || strlen($value) > 200 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("ImportManifest {$field} must be a bounded nonempty string");
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}(\/[a-z0-9_.-]{1,64})*$/', $sourceSystem) !== 1 || strlen($sourceSystem) > 200) {
            throw new InvalidArgumentException('ImportManifest sourceSystem must match the product-agnostic source namespace grammar');
        }
        if ($sourceExportRevision < 0 || $recordCount < 0) {
            throw new InvalidArgumentException('ImportManifest revision and recordCount must be non-negative');
        }
        foreach (['recordsChecksum' => $recordsChecksum, 'manifestDigest' => $manifestDigest] as $field => $digest) {
            if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
                throw new InvalidArgumentException("ImportManifest {$field} must be a valid sha256 digest");
            }
        }
        if (trim($sanitizationPolicy['policyVersion']) === '' || strlen($sanitizationPolicy['policyVersion']) > 200) {
            throw new InvalidArgumentException('ImportManifest sanitizationPolicy.policyVersion must be bounded and nonempty');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'schema' => 'external.import.manifest.v1',
            'batchId' => $this->batchId,
            'sourceSystem' => $this->sourceSystem,
            'sourceInstallation' => $this->sourceInstallation,
            'sourceBook' => $this->sourceBook,
            'sourceExportRevision' => $this->sourceExportRevision,
            'exportCutoff' => $this->exportCutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'installationRef' => $this->installationRef,
            'environment' => $this->environment,
            'sellerRef' => $this->sellerRef,
            'recordCount' => $this->recordCount,
            'recordsChecksum' => $this->recordsChecksum,
            'totalsByCurrency' => array_map(static fn (ImportCurrencyTotal $t): array => $t->toWire(), $this->totalsByCurrency),
            'sanitizationPolicy' => $this->sanitizationPolicy,
            'digest' => $this->manifestDigest,
        ];
        if ($this->sourceLineage !== null) {
            $wire['sourceLineage'] = $this->sourceLineage;
        }
        if ($this->importPolicyVersion !== null) {
            $wire['importPolicyVersion'] = $this->importPolicyVersion;
        }
        if ($this->sourceFreezeCertificateRef !== null) {
            $wire['sourceFreezeCertificateRef'] = $this->sourceFreezeCertificateRef;
        }

        return $wire;
    }
}
