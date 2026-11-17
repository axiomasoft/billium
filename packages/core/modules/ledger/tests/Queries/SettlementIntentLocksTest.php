<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Queries;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Ledger\Contracts\SettlementIntentLocks;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Billium\Platform\Data\Installation\PlatformReceipt;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class SettlementIntentLocksTest extends LedgerTestCase
{
    public function test_plans_only_authenticated_installed_seller_environment_and_account_and_acquires_the_same_guarded_row(): void
    {
        $this->obligation();
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('declare', $this->account(), 'intent-a', 'RUB', ['obligation-a']))->orThrow();
        $planner = $this->application()->make(SettlementIntentLocks::class);
        $request = $planner->plan($this->account(), 'intent-a');
        self::assertSame(LockRank::SettlementIntent, $request->rank);
        self::assertSame('billium_lock_settlement_intent', $request->guardedRoutine);
        self::assertSame([self::integer($this->value('SELECT id FROM billium_settlement_intent'))], $request->keys);
        $this->runner()->run($this->spec(), static function (TransactionContext $ctx) use ($request): PlatformReceipt {
            self::assertSame($request->keys, $ctx->lock($request));

            return self::receipt();
        })->orThrow();
        foreach ([[$this->account('other-account'), 'intent-a'], [new BillingAccountScope('seller-b', 'test', 'account-a'), 'intent-a'],
            [new BillingAccountScope('seller-a', 'production', 'account-a'), 'intent-a'], [$this->account(), 'unknown'], [$this->account(), '']] as [$scope, $ref]) {
            try {
                $planner->plan($scope, $ref);
                self::fail('Foreign/missing intent yielded a lock');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
            }
        }
        $this->callers->set(new Caller('seller-a', 'test', 'other-installation', 'reader'));
        $this->expectException(ProblemException::class);
        $planner->plan($this->account(), 'intent-a');
    }
}
