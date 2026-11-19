<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * FinBridge-contract export payload derived from an AccountingFactEnvelope.
 *
 * Strictly conveys economic facts without chart-of-accounts resolution or GL entry writes.
 */
#[Api(since: '1.0')]
final readonly class FinBridgeExportEnvelope
{
    public const string SCHEMA_V1 = 'finbridge.fact.export.v1';

    /**
     * @param  list<string>  $linkedCashFactRefs
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $schemaVersion,
        public string $sourceFactRef,
        public AccountingFactKind $factKind,
        public AccountingFactCategory $category,
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $sourceLineage,
        public string $currency,
        public int $amountMinor,
        public string $amountDecimal,
        public DateTimeImmutable $occurredAt,
        public string $snapshotDigest,
        public string $exportDigest,
        public ?string $originalFactRef = null,
        public array $linkedCashFactRefs = [],
        public bool $exceptional = false,
        public array $attributes = [],
    ) {
        if ($factKind->category() !== $category) {
            throw new InvalidArgumentException("FinBridgeExportEnvelope category {$category->value} does not match factKind {$factKind->value}");
        }
        foreach ([
            'schemaVersion' => $schemaVersion,
            'sourceFactRef' => $sourceFactRef,
            'installationRef' => $installationRef,
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'sourceLineage' => $sourceLineage,
            'currency' => $currency,
            'amountDecimal' => $amountDecimal,
        ] as $name => $value) {
            if (trim($value) === '' || strlen($value) > 256 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("FinBridgeExportEnvelope {$name} must be a bounded nonempty string");
            }
        }
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('FinBridgeExportEnvelope amountMinor must be strictly positive');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $snapshotDigest) !== 1) {
            throw new InvalidArgumentException('FinBridgeExportEnvelope snapshotDigest must be a valid sha256 digest');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $exportDigest) !== 1) {
            throw new InvalidArgumentException('FinBridgeExportEnvelope exportDigest must be a valid sha256 digest');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'sourceFactRef' => $this->sourceFactRef,
            'factKind' => $this->factKind->value,
            'category' => $this->category->value,
            'installationRef' => $this->installationRef,
            'sellerRef' => $this->sellerRef,
            'environment' => $this->environment,
            'sourceLineage' => $this->sourceLineage,
            'currency' => $this->currency,
            'amountMinor' => $this->amountMinor,
            'amountDecimal' => $this->amountDecimal,
            'occurredAt' => $this->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'snapshotDigest' => $this->snapshotDigest,
            'originalFactRef' => $this->originalFactRef,
            'linkedCashFactRefs' => $this->linkedCashFactRefs,
            'exceptional' => $this->exceptional,
            'attributes' => $this->attributes,
            'exportDigest' => $this->exportDigest,
        ];
    }
}
