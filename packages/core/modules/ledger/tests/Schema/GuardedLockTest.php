<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Internal\Runner\LockSet;
use Billium\Core\Tests\Support\FixedCallerContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GuardedLockTest extends TestCase
{
    public function test_guarded_lock_calls_a_scoped_owner_routine_instead_of_requesting_update_privilege(): void
    {
        $calls = [];
        $locks = new LockSet(static function (string $sql, array $bindings) use (&$calls): array {
            $calls[] = [$sql, $bindings];

            return [2, 3];
        }, FixedCallerContext::default()->current());
        self::assertSame([2, 3], $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [3, 2], guardedRoutine: 'billium_lock_obligation')));
        self::assertSame('SELECT lock_key FROM public."billium_lock_obligation"(?::bigint[], ?::text, ?::text, ?::boolean)', $calls[0][0]);
        self::assertSame(['{2,3}', 'seller-a', 'test', false], $calls[0][1]);
    }

    public function test_a_guarded_lock_cannot_substitute_a_different_table_or_text_key(): void
    {
        foreach ([fn () => new LockRequest(LockRank::Obligation, 'billium_obligation', [1], guardedRoutine: 'billium_lock_payment'),
            fn () => new LockRequest(LockRank::Obligation, 'billium_obligation', ['text'], guardedRoutine: 'billium_lock_obligation')] as $invalid) {
            try {
                $invalid();
                self::fail('unsafe lock target');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
