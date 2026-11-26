<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Billium\Contracts\Data\Money;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Per-(sellerRef, currency, recordKind) reconciliation bucket comparing manifest/staged records against Billium state.
 *
 * Currency buckets are strictly isolated and never summed across currencies.
 */
#[Api(since: '1.0')]
final readonly class ImportCurrencyReconciliation
{
    public function __construct(
        public string $sellerRef,
        public string $currency,
        public ImportRecordKind $recordKind,
        public int $expectedCount,
        public Money $expectedTotal,
        public int $appliedCount,
        public Money $appliedTotal,
        public int $queuedOrParkedCount,
        public Money $queuedOrParkedTotal,
        public int $unanchoredCount,
        public Money $unanchoredTotal,
        public Money $variance,
        public bool $balanced,
    ) {
        if (trim($sellerRef) === '' || strlen($sellerRef) > 200) {
            throw new InvalidArgumentException('ImportCurrencyReconciliation sellerRef must be bounded and nonempty');
        }
        foreach ([$expectedTotal, $appliedTotal, $queuedOrParkedTotal, $unanchoredTotal, $variance] as $money) {
            if ($money->currency->code !== $currency) {
                throw new InvalidArgumentException('ImportCurrencyReconciliation money currencies must match bucket currency');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'sellerRef' => $this->sellerRef,
            'currency' => $this->currency,
            'recordKind' => $this->recordKind->value,
            'expectedCount' => $this->expectedCount,
            'expectedTotal' => $this->expectedTotal->toWire(),
            'appliedCount' => $this->appliedCount,
            'appliedTotal' => $this->appliedTotal->toWire(),
            'queuedOrParkedCount' => $this->queuedOrParkedCount,
            'queuedOrParkedTotal' => $this->queuedOrParkedTotal->toWire(),
            'unanchoredCount' => $this->unanchoredCount,
            'unanchoredTotal' => $this->unanchoredTotal->toWire(),
            'variance' => $this->variance->toWire(),
            'balanced' => $this->balanced,
            'authoritativeCurrentBalance' => false,
        ];
    }
}
