<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Migration;

use Billium\Platform\Internal\Migration\MigrationDigest;
use InvalidArgumentException;
use Packman\Attributes\Api;

/** Totals are signed decimal minor-unit strings, and the checksum covers the selected facts and retained identities. */
#[Api(since: '1.0')]
final readonly class ReconciliationChecksum
{
    public const array COUNTS = ['allocations', 'captured', 'corrections', 'obligations', 'receipts', 'refunded', 'reservations', 'sourceMaps', 'tombstones'];

    public const array TOTALS = ['allocated', 'captured', 'obligated', 'open', 'refunded', 'reserved'];

    /**
     * @param  array<string,mixed>  $counts
     * @param  array<string,mixed>  $totals
     */
    public function __construct(
        public string $sellerRef,
        public string $currency,
        array $counts,
        array $totals,
        public string $checksum,
    ) {
        $countKeys = array_keys($counts);
        $totalKeys = array_keys($totals);
        sort($countKeys, SORT_STRING);
        sort($totalKeys, SORT_STRING);
        $validCounts = array_filter($counts, static fn (mixed $count): bool => is_int($count) && $count >= 0 && $count <= 9007199254740991);
        if (trim($sellerRef) === '' || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || ! MigrationDigest::valid($checksum)
            || $countKeys !== self::COUNTS || $totalKeys !== self::TOTALS || $validCounts !== $counts) {
            throw new InvalidArgumentException('Incomplete reconciliation checksum');
        }
        $validTotals = array_filter($totals, static fn (mixed $total): bool => is_string($total) && preg_match('/^(0|-?[1-9][0-9]*)$/D', $total) === 1);
        if ($validTotals !== $totals) {
            throw new InvalidArgumentException('Reconciliation totals require exact minor units');
        }
        $this->counts = $validCounts;
        $this->totals = $validTotals;
    }

    /** @var array<string,int> */
    public array $counts;

    /** @var array<string,string> */
    public array $totals;

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['sellerRef' => $this->sellerRef, 'currency' => $this->currency, 'counts' => $this->counts, 'totals' => $this->totals, 'checksum' => $this->checksum];
    }
}
