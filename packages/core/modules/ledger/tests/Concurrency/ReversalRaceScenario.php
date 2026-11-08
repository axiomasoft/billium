<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Illuminate\Foundation\Application;
use PDOException;

/** One runtime process calling billium_reverse_allocation for the same original under its own reversal identity. */
final class ReversalRaceScenario implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        $allocation = $args['allocation'] ?? null;
        if (! is_int($allocation)) {
            throw new \LogicException('invalid reversal fixture');
        }
        $statement = TestEnvironment::pdo()->prepare('SELECT public.billium_reverse_allocation(?,?,?,?,?,?,?,?,?,?)');
        try {
            $statement->execute(['inst-test', 'seller-a', 'test', 'account-a', 'RUB', $allocation, 'race-'.$index, 0, 'race', LedgerTestCase::digest()]);

            return ['reversed' => true];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), ProblemCode::IdempotencyConflict->value)) {
                throw new ProblemException(ProblemCode::IdempotencyConflict);
            }
            throw $e;
        }
    }
}
