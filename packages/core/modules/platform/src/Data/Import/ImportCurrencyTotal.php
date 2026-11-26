<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Historical import total isolated per seller, currency and record kind; never an authoritative current balance.
 */
#[Api(since: '1.0')]
final readonly class ImportCurrencyTotal
{
    public function __construct(
        public string $sellerRef,
        public string $currency,
        public ImportRecordKind $recordKind,
        public int $count,
        public Money $totalAmount,
        public bool $authoritativeCurrentBalance = false,
    ) {
        if (trim($sellerRef) === '' || strlen($sellerRef) > 200 || str_contains($sellerRef, "\0")) {
            throw new InvalidArgumentException('ImportCurrencyTotal sellerRef must be a bounded nonempty string');
        }
        if ($currency !== $totalAmount->currency->code) {
            throw new InvalidArgumentException('ImportCurrencyTotal currency must match totalAmount currency');
        }
        if ($count < 0) {
            throw new InvalidArgumentException('ImportCurrencyTotal count must be non-negative');
        }
        if ($authoritativeCurrentBalance) {
            throw new InvalidArgumentException('Historical import totals cannot claim authoritativeCurrentBalance = true without reconciliation');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'sellerRef' => $this->sellerRef,
            'currency' => $this->currency,
            'recordKind' => $this->recordKind->value,
            'count' => $this->count,
            'totalAmount' => $this->totalAmount->toWire(),
            'authoritativeCurrentBalance' => false,
        ];
    }
}
