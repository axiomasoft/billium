<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use Billium\Contracts\Data\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Immutable canonical accounting fact keyed by stable economic identity (`sourceFactRef`).
 */
#[Api(since: '1.0')]
final readonly class AccountingFactEnvelope
{
    public const string SCHEMA_V1 = 'billium.accounting.fact.v1';

    /**
     * @param  list<string>  $linkedCashFactRefs
     * @param  array<string, mixed>  $metadata
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
        public string $economicEffectRef,
        public Money $money,
        public DateTimeImmutable $occurredAt,
        public string $snapshotDigest,
        public ?string $originalFactRef = null,
        public array $linkedCashFactRefs = [],
        public bool $exceptional = false,
        public array $metadata = [],
    ) {
        if ($factKind->category() !== $category) {
            throw new InvalidArgumentException("AccountingFactEnvelope category {$category->value} does not match factKind {$factKind->value}");
        }
        foreach ([
            'schemaVersion' => $schemaVersion,
            'sourceFactRef' => $sourceFactRef,
            'installationRef' => $installationRef,
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'sourceLineage' => $sourceLineage,
            'economicEffectRef' => $economicEffectRef,
        ] as $name => $value) {
            if (trim($value) === '' || strlen($value) > 256 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("AccountingFactEnvelope {$name} must be a bounded nonempty string");
            }
        }
        if ($money->minor <= 0) {
            throw new InvalidArgumentException('AccountingFactEnvelope money must be strictly positive in minor units');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $snapshotDigest) !== 1) {
            throw new InvalidArgumentException('AccountingFactEnvelope snapshotDigest must be a valid sha256 digest');
        }
        if ($originalFactRef !== null && (trim($originalFactRef) === '' || strlen($originalFactRef) > 256)) {
            throw new InvalidArgumentException('AccountingFactEnvelope originalFactRef must be bounded when provided');
        }
        foreach ($linkedCashFactRefs as $linked) {
            if (trim($linked) === '' || strlen($linked) > 256) {
                throw new InvalidArgumentException('AccountingFactEnvelope linkedCashFactRefs entries must be bounded nonempty strings');
            }
        }
    }

    /**
     * Canonical wire representation using integer minor units / MoneyWire strings (zero floats).
     *
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
            'economicEffectRef' => $this->economicEffectRef,
            'money' => $this->money->toWire(),
            'amountMinor' => $this->money->minor,
            'occurredAt' => $this->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'snapshotDigest' => $this->snapshotDigest,
            'originalFactRef' => $this->originalFactRef,
            'linkedCashFactRefs' => $this->linkedCashFactRefs,
            'exceptional' => $this->exceptional,
            'metadata' => $this->metadata,
        ];
    }
}
