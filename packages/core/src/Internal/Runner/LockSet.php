<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Runner;

use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Closure;
use Packman\Attributes\Internal;

/**
 * The only application row-lock interface (invariant-model). Rows are locked in the single global order
 * (rank, segment, key): a request below the current position is allowed only when every key is already held in a
 * covering mode (reentrant), new keys inside a rank only ascend, and a held shared lock is never upgraded. Every
 * predicate carries the authenticated scope, so a foreign seller/installation row is never locked.
 */
#[Internal]
final class LockSet
{
    /** @var array<string, array{table: string, column: string, scope: LockScope, keys: array<string, bool>}> group => held keys (key => shared) */
    private array $held = [];

    /** @var array{0: int, 1: int, 2: int|string|null} rank, segment, last key */
    private array $position = [0, 0, null];

    /** @param Closure(string, list<mixed>): list<int|string> $executor runs one locking SELECT and returns the locked keys */
    public function __construct(private readonly Closure $executor, private readonly Caller $caller) {}

    /** @return list<int|string> locked keys among those requested, in lock order */
    public function acquire(LockRequest ...$requests): array
    {
        $groups = [];
        foreach ($requests as $request) {
            $group = self::group($request);
            if (isset($groups[$group])) {
                $previous = $groups[$group];
                if ($previous->table !== $request->table || $previous->keyColumn !== $request->keyColumn
                    || $previous->scope !== $request->scope || $previous->shared !== $request->shared
                    || $previous->guardedRoutine !== $request->guardedRoutine) {
                    throw new LockOrderViolation("Rank segment {$group} admits one table and one mode per request");
                }
                $request = new LockRequest($request->rank, $request->table, [...$previous->keys, ...$request->keys], $request->shared, $request->scope, $request->keyColumn, $request->segment, $request->guardedRoutine);
            }
            $groups[$group] = $request;
        }
        uasort($groups, static fn (LockRequest $a, LockRequest $b): int => [$a->rank->value, $a->segment] <=> [$b->rank->value, $b->segment]);

        $locked = [];
        foreach ($groups as $group => $request) {
            array_push($locked, ...$this->acquireGroup($group, $request));
        }

        return $locked;
    }

    /** @return list<int|string> */
    public function heldKeys(LockRequest $like): array
    {
        return array_map(
            static fn (string $key): int|string => $like->textKeys() ? $key : (int) $key,
            array_keys($this->held[self::group($like)]['keys'] ?? []),
        );
    }

    /** @return list<int|string> */
    private function acquireGroup(string $group, LockRequest $request): array
    {
        $held = $this->held[$group] ?? null;
        if ($held !== null && ($held['table'] !== $request->table || $held['column'] !== $request->keyColumn || $held['scope'] !== $request->scope)) {
            throw new LockOrderViolation("Rank segment {$group} already locks {$held['table']}.{$held['column']}");
        }
        $comparison = [$request->rank->value, $request->segment] <=> [$this->position[0], $this->position[1]];
        $already = [];
        $new = [];
        foreach ($request->keys as $key) {
            $shared = $held['keys'][(string) $key] ?? null;
            if ($shared === null) {
                $new[] = $key;

                continue;
            }
            if ($shared && ! $request->shared) {
                throw new LockOrderViolation("Upgrade of a shared lock on {$request->table} {$key} is forbidden");
            }
            $already[] = $key;
        }
        if ($new !== []) {
            if ($comparison < 0) {
                throw new LockOrderViolation(sprintf(
                    'Lock %s rank %d segment %d requested after rank %d segment %d (D27)',
                    $request->table, $request->rank->value, $request->segment, $this->position[0], $this->position[1],
                ));
            }
            $last = $this->position[2];
            if ($comparison === 0 && $last !== null && self::compare($new[0], $last) <= 0) {
                throw new LockOrderViolation("New {$request->table} keys must ascend past {$last} inside rank {$request->rank->value}");
            }
        }
        if ($new === []) {
            return $already;
        }

        $found = ($this->executor)(...$this->statement($request, $new));
        $this->held[$group] ??= ['table' => $request->table, 'column' => $request->keyColumn, 'scope' => $request->scope, 'keys' => []];
        foreach ($found as $key) {
            $this->held[$group]['keys'][(string) $key] = $request->shared;
        }
        $this->position = [$request->rank->value, $request->segment, $new[array_key_last($new)]];
        $locked = [...$already, ...$found];
        usort($locked, self::compare(...));

        return $locked;
    }

    /**
     * @param  list<int|string>  $keys
     * @return array{0: string, 1: list<mixed>}
     */
    private function statement(LockRequest $request, array $keys): array
    {
        $text = $request->textKeys();
        $column = '"'.$request->keyColumn.'"';
        $literal = '{'.implode(',', array_map(
            static fn (int|string $key): string => $text ? '"'.addcslashes((string) $key, '"\\').'"' : (string) $key,
            $keys,
        )).'}';
        // D60: PostgreSQL FOR UPDATE itself requires UPDATE privilege. Protected owners expose lock-only routines.
        if ($request->guardedRoutine !== null) {
            return [
                'SELECT lock_key FROM public."'.$request->guardedRoutine.'"(?::bigint[], ?::text, ?::text, ?::boolean)',
                [$literal, $this->caller->sellerRef, $this->caller->environment, $request->shared],
            ];
        }
        $sql = 'SELECT '.$column.' AS lock_key FROM public."'.$request->table.'" WHERE '.$column.' = ANY(?::'.($text ? 'text' : 'bigint').'[])';
        $bindings = [$literal];
        if ($request->scope === LockScope::Seller) {
            $sql .= ' AND seller_ref = ? AND environment = ?';
            $bindings[] = $this->caller->sellerRef;
            $bindings[] = $this->caller->environment;
        } else {
            $sql .= ' AND installation_ref = ?';
            $bindings[] = $this->caller->installationRef;
        }
        $mode = $request->shared ? ' FOR SHARE' : ($request->noKeyUpdate ? ' FOR NO KEY UPDATE' : ' FOR UPDATE');
        $sql .= ' ORDER BY '.$column.($text ? ' COLLATE "C"' : '').$mode;

        return [$sql, $bindings];
    }

    private static function group(LockRequest $request): string
    {
        return $request->rank->value.':'.$request->segment;
    }

    private static function compare(int|string $a, int|string $b): int
    {
        return is_int($a) && is_int($b) ? $a <=> $b : strcmp((string) $a, (string) $b);
    }
}
