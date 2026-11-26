<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Contracts\Codec;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Canonical seller/environment-scoped source identity independent of batch ID, filename or mapper policy version.
 */
#[Api(since: '1.0')]
final readonly class ImportSourceIdentity
{
    public function __construct(
        public string $installationRef,
        public string $environment,
        public string $sellerRef,
        public string $sourceSystem,
        public string $sourceInstallation,
        public string $sourceBook,
        public string $sourceRecordId,
        public ImportRecordKind $recordKind,
    ) {
        foreach ([
            'installationRef' => $installationRef,
            'environment' => $environment,
            'sellerRef' => $sellerRef,
            'sourceInstallation' => $sourceInstallation,
            'sourceBook' => $sourceBook,
            'sourceRecordId' => $sourceRecordId,
        ] as $field => $value) {
            if (trim($value) === '' || strlen($value) > 200 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("ImportSourceIdentity {$field} must be a bounded nonempty string");
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}(\/[a-z0-9_.-]{1,64})*$/', $sourceSystem) !== 1 || strlen($sourceSystem) > 200) {
            throw new InvalidArgumentException('ImportSourceIdentity sourceSystem must match the product-agnostic source namespace grammar');
        }
    }

    /**
     * Deterministic SHA-256 fingerprint over (installationRef, environment, sellerRef, sourceInstallation, sourceBook, sourceRecordId, recordKind).
     * Intentionally excludes batchId, filename, sourceExportRevision, sourceSystem alias and importPolicyVersion.
     */
    public function identityDigest(?Codec $codec = null): string
    {
        $canonical = ($codec ?? new Codec)->canonicalize([
            'environment' => $this->environment,
            'installationRef' => $this->installationRef,
            'recordKind' => $this->recordKind->value,
            'sellerRef' => $this->sellerRef,
            'sourceBook' => $this->sourceBook,
            'sourceInstallation' => $this->sourceInstallation,
            'sourceRecordId' => $this->sourceRecordId,
        ]);

        return 'sha256:'.hash('sha256', $canonical);
    }

    /**
     * Stable economic fact reference preserved across batch retries, repackaging and mapper policy version updates.
     */
    public function stableSourceFactRef(int $sourceRecordRevision, ?Codec $codec = null): string
    {
        if ($sourceRecordRevision < 0) {
            throw new InvalidArgumentException('sourceRecordRevision must be non-negative');
        }
        $canonical = ($codec ?? new Codec)->canonicalize([
            'identityDigest' => $this->identityDigest($codec),
            'sourceRecordRevision' => $sourceRecordRevision,
        ]);

        return 'import:'.hash('sha256', $canonical);
    }
}
