<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Core\Tests\TestEnvironment;
use Illuminate\Foundation\Application;
use PDOException;

final class AllocationRaceScenario implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        $obligations = $args['obligations'];
        if (! is_array($obligations) || ! is_int($obligations[$index % 2] ?? null) || ! is_int($args['funds'] ?? null)) {
            throw new \LogicException('invalid race fixture');
        }
        $pdo = TestEnvironment::pdo();
        $s = $pdo->prepare('SELECT public.billium_allocate(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        try {
            $s->execute(['inst-test', 'seller-a', 'test', 'account-a', 'RUB', $obligations[$index % 2], $args['source'] === 'payment' ? $args['funds'] : null, $args['source'] === 'credit' ? $args['funds'] : null, null, 50, null, 'race', 'pro_rata_open/1', 'race-'.$index, LedgerTestCase::digest()]);

            return ['accepted' => true];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), ProblemCode::AllocationExceedsAvailable->value)) {
                throw new ProblemException(ProblemCode::AllocationExceedsAvailable);
            }
            throw $e;
        }
    }
}
