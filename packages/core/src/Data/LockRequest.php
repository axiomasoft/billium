<?php

declare(strict_types=1);

namespace Billium\Core\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Core\Enums\LockRank;
use Billium\Core\Enums\LockScope;
use InvalidArgumentException;

/**
 * One planned row set of a LockSet (invariant-model). The owning module names its own table; LockSet orders by
 * (rank, segment, key) and filters by the caller scope. Keys are ids (int) or, for installation rows ordered by a
 * text key such as module_id, strings compared bytewise (COLLATE "C").
 */
#[Api]
final readonly class LockRequest
{
    /** @var list<int>|list<string> */
    public array $keys;

    /** @param list<int>|list<string> $keys */
    public function __construct(
        public LockRank $rank,
        public string $table,
        array $keys,
        public bool $shared = false,
        public LockScope $scope = LockScope::Seller,
        public string $keyColumn = 'id',
        public int $segment = 0,
        public ?string $guardedRoutine = null,
    ) {
        if ($rank === LockRank::Claim) {
            throw new InvalidArgumentException('The claim is an insert, not a row lock');
        }
        if (preg_match('/^billium_[a-z0-9_]+$/', $table) !== 1 || preg_match('/^[a-z][a-z0-9_]*$/', $keyColumn) !== 1) {
            throw new InvalidArgumentException('Lock target must be a billium_ table and a plain column (D45)');
        }
        if ($segment < 0 || $segment > 9) {
            throw new InvalidArgumentException('Lock segment out of range');
        }
        if ($guardedRoutine !== null && ($guardedRoutine !== 'billium_lock_'.substr($table, strlen('billium_'))
            || $scope !== LockScope::Seller || $keyColumn !== 'id'
            || count(array_filter($keys, is_int(...))) !== count($keys))) {
            throw new InvalidArgumentException('A guarded lock uses scoped ids and a narrow billium_lock_ routine');
        }
        if ($keys === []) {
            throw new InvalidArgumentException('A lock request names at least one key');
        }
        $ints = array_filter($keys, is_int(...));
        $strings = array_filter($keys, static fn (mixed $key): bool => is_string($key) && $key !== '');
        if (count($ints) !== count($keys) && count($strings) !== count($keys)) {
            throw new InvalidArgumentException('Lock keys are all ids or all nonempty strings');
        }
        $unique = array_values(array_unique($keys, SORT_REGULAR));
        usort($unique, static fn (int|string $a, int|string $b): int => is_int($a) && is_int($b) ? $a <=> $b : strcmp((string) $a, (string) $b));
        /** @var list<int>|list<string> $unique */
        $this->keys = $unique;
    }

    public function textKeys(): bool
    {
        return is_string($this->keys[0]);
    }
}
