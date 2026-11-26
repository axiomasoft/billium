<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Immutable reference to one sanitized evidence item attached to an external import record.
 */
#[Api(since: '1.0')]
final readonly class ImportEvidenceRef
{
    public function __construct(
        public string $evidenceRef,
        public string $kind,
        public string $digest,
        public bool $sanitized,
        public ?DateTimeImmutable $capturedAt = null,
        public ?string $sourceUri = null,
    ) {
        if (trim($evidenceRef) === '' || strlen($evidenceRef) > 200 || str_contains($evidenceRef, "\0")) {
            throw new InvalidArgumentException('ImportEvidenceRef evidenceRef must be a bounded nonempty string');
        }
        if (trim($kind) === '' || strlen($kind) > 100 || str_contains($kind, "\0")) {
            throw new InvalidArgumentException('ImportEvidenceRef kind must be a bounded nonempty string');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            throw new InvalidArgumentException('ImportEvidenceRef digest must be a valid sha256 digest');
        }
        if ($sourceUri !== null && (trim($sourceUri) === '' || strlen($sourceUri) > 200 || str_contains($sourceUri, "\0"))) {
            throw new InvalidArgumentException('ImportEvidenceRef sourceUri must be a bounded nonempty string');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        $wire = [
            'evidenceRef' => $this->evidenceRef,
            'kind' => $this->kind,
            'digest' => $this->digest,
            'sanitized' => $this->sanitized,
        ];
        if ($this->capturedAt !== null) {
            $wire['capturedAt'] = $this->capturedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        if ($this->sourceUri !== null) {
            $wire['sourceUri'] = $this->sourceUri;
        }

        return $wire;
    }
}
