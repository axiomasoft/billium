<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use Billium\Contracts\Data\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Explicit accounting consumer applied receipt, keyed by stable sourceFactRef and stored separately from delivery ACKs.
 */
#[Api(since: '1.0')]
final readonly class AccountingAppliedReceipt
{
    public function __construct(
        public string $receiptRef,
        public string $sourceFactRef,
        public AccountingFactCategory $category,
        public AccountingFactKind $factKind,
        public Money $money,
        public string $consumerRef,
        public string $snapshotDigest,
        public DateTimeImmutable $appliedAt,
        public bool $replayed = false,
    ) {
        if ($factKind->category() !== $category) {
            throw new InvalidArgumentException("AccountingAppliedReceipt category {$category->value} does not match factKind {$factKind->value}");
        }
        foreach ([
            'receiptRef' => $receiptRef,
            'sourceFactRef' => $sourceFactRef,
            'consumerRef' => $consumerRef,
        ] as $name => $value) {
            if (trim($value) === '' || strlen($value) > 256 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("AccountingAppliedReceipt {$name} must be a bounded nonempty string");
            }
        }
        if ($money->minor <= 0) {
            throw new InvalidArgumentException('AccountingAppliedReceipt money must be strictly positive');
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $snapshotDigest) !== 1) {
            throw new InvalidArgumentException('AccountingAppliedReceipt snapshotDigest must be a valid sha256 digest');
        }
    }
}
