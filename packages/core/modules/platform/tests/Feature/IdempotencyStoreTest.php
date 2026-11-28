<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Idempotency\FinancialIdempotencyStore;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('INV-05')]
#[Group('D51')]
#[Group('pg')]
final class IdempotencyStoreTest extends PlatformTestCase
{
    private const string LEDGER = "command_kind NOT LIKE 'platform.%'";

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_four_outcomes_new_duplicate_refused_and_conflict(): void
    {
        $new = $this->runner()->run($this->spec(key: 'k-1'), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'once')]));
        self::assertTrue($new->accepted);
        self::assertFalse($new->replayed);

        $duplicate = $this->runner()->run($this->spec(key: 'k-1'), static fn (): Receipt => throw new LogicException('body must not run'));
        self::assertTrue($duplicate->replayed);
        self::assertSame($new->receiptId, $duplicate->receiptId);
        self::assertSame($new->body, $duplicate->body);

        $refused = $this->runner()->run($this->spec(key: 'k-2'), static fn (): Receipt => throw new ProblemException(ProblemCode::PolicyUnknown));
        $refusedAgain = $this->runner()->run($this->spec(key: 'k-2'), static fn (): Receipt => self::receipt());
        self::assertTrue($refusedAgain->replayed);
        self::assertFalse($refusedAgain->accepted);
        self::assertSame(ProblemCode::PolicyUnknown, $refusedAgain->problem);
        self::assertSame($refused->receiptId, $refusedAgain->receiptId);

        try {
            $this->runner()->run($this->spec(key: 'k-1', digest: 'sha256:'.str_repeat('f', 64)), static fn (): Receipt => self::receipt(['replaced' => true]));
            self::fail('another digest under the same key conflicts');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::IdempotencyConflict, $problem->problem);
            self::assertStringNotContainsString('once', $problem->getMessage(), 'conflict discloses nothing of the original');
        }
        // The original command and receipt stay untouched.
        $row = $this->row('SELECT digest, outcome FROM billium_idempotency_receipt WHERE command_key = ?', ['k-1']);
        self::assertSame(['digest' => 'sha256:'.str_repeat('a', 64), 'outcome' => 'accepted'], $row);
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertSame(2, $this->rows('billium_idempotency_receipt', self::LEDGER));
    }

    public function test_none_policy_codes_leave_no_row(): void
    {
        foreach ([ProblemCode::ValidationInvalid, ProblemCode::LookupUnavailable, ProblemCode::TransportUnavailable, ProblemCode::IdempotencyInFlight] as $code) {
            try {
                $this->runner()->run($this->spec(key: 'k-none-'.$code->name), static fn (): Receipt => throw new ProblemException($code));
                self::fail('none-policy code propagates');
            } catch (ProblemException $problem) {
                self::assertSame($code, $problem->problem);
            }
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', self::LEDGER));
    }

    public function test_scope_distinguishes_seller_environment_kind_namespace_and_key(): void
    {
        $base = ['kind' => 'ledger.record', 'key' => 'k', 'seller' => 'seller-a', 'environment' => 'test'];
        $variants = [
            $base,
            ['seller' => 'seller-b'] + $base,
            ['environment' => 'live'] + $base,
            ['kind' => 'ledger.declare'] + $base,
            ['kind' => 'usage.record'] + $base,
            ['key' => 'k-other'] + $base,
        ];
        $ids = [];
        foreach ($variants as $variant) {
            $this->callers->set(new Caller($variant['seller'], $variant['environment'], self::INSTALLATION, 'actor-1'));
            $outcome = $this->runner()->run(
                $this->spec(kind: $variant['kind'], key: $variant['key'], modules: ['platform']),
                static fn (): Receipt => self::receipt(['for' => $variant]),
            );
            self::assertFalse($outcome->replayed, json_encode($variant, JSON_THROW_ON_ERROR));
            $ids[] = $outcome->receiptId;
        }
        self::assertCount(6, array_unique($ids));
        self::assertSame(6, $this->rows('billium_idempotency_receipt', self::LEDGER));

        // A different digest in another seller's scope is not a conflict: scopes never see each other.
        $this->callers->set(new Caller('seller-c', 'test', self::INSTALLATION, 'actor-1'));
        $other = $this->runner()->run($this->spec(kind: 'ledger.record', key: 'k', digest: 'sha256:'.str_repeat('9', 64), modules: ['platform']), static fn (): Receipt => self::receipt());
        self::assertFalse($other->replayed);
    }

    public function test_a_retained_key_never_replays_into_another_financial_scope_of_the_same_seller(): void
    {
        // The receipt key is (environment, seller, kind, key); the financial scope is checked on replay, so the same
        // seller's other intent owner cannot read or reuse a receipt it did not create.
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'actor-1', intentOwnerNamespaces: ['shop-a', 'shop-b']));
        $scope = static fn (string $namespace): FinancialScope => new FinancialScope(self::INSTALLATION, 'seller-a', 'test', $namespace);
        $first = $this->runner()->run($this->spec(key: 'k-scoped', financialScope: $scope('shop-a')), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'shop-a')]));
        self::assertTrue($first->accepted);

        try {
            $this->runner()->run($this->spec(key: 'k-scoped', financialScope: $scope('shop-b')), static fn (): Receipt => throw new LogicException('body must not run'));
            self::fail('another financial scope under the same key conflicts');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::IdempotencyConflict, $problem->problem);
            self::assertStringNotContainsString('shop-a', $problem->getMessage());
        }
        try {
            $this->runner()->run($this->spec(key: 'k-unselected', financialScope: $scope('shop-c')), static fn (): Receipt => throw new LogicException('body must not run'));
            self::fail('a scope outside the authenticated selection is refused');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::AuthDenied, $problem->problem);
        }

        // The runner's digest already differs per scope; the store refuses a matching digest from another scope as well.
        $digest = self::text($this->value("SELECT digest FROM billium_idempotency_receipt WHERE command_key = 'k-scoped'"));
        $store = $this->application()->make(IdempotencyStore::class);
        self::assertInstanceOf(FinancialIdempotencyStore::class, $store);
        $db = $this->application()->make(DatabaseManager::class)->connection();
        $db->beginTransaction();
        try {
            $store->claimScoped($this->callers->current(), $scope('shop-b'), 'ledger.test_effect', 'k-scoped', $digest);
            self::fail('a retained claim of another financial scope conflicts even with the same digest');
        } catch (ProblemException $problem) {
            self::assertSame(ProblemCode::IdempotencyConflict, $problem->problem);
        } finally {
            $db->rollBack();
        }

        $replay = $this->runner()->run($this->spec(key: 'k-scoped', financialScope: $scope('shop-a')), static fn (): Receipt => throw new LogicException('body must not run'));
        self::assertTrue($replay->replayed);
        self::assertSame($first->receiptId, $replay->receiptId);
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', self::LEDGER));
    }

    public function test_replay_after_restart_returns_the_original_receipt(): void
    {
        $first = $this->runner()->run($this->spec(key: 'k-restart'), static fn (TransactionContext $context): Receipt => self::receipt(['effect' => self::effect($context, 'x')]));
        $this->application()->make(DatabaseManager::class)->purge('pgsql');
        $this->application()->forgetInstance(OperationRunner::class);
        $this->application()->forgetInstance(IdempotencyStore::class);
        $replay = $this->runner()->run($this->spec(key: 'k-restart'), static fn (): Receipt => throw new LogicException('no second effect'));
        self::assertTrue($replay->replayed);
        self::assertSame($first->body, $replay->body);
        self::assertSame(1, $this->rows('billium_test_effect'));
    }

    public function test_replay_preserves_nested_json_objects_lists_and_numeric_object_keys(): void
    {
        $first = $this->runner()->run($this->spec(key: 'k-json-shapes'), static fn (): Receipt => self::receipt([
            'metadata' => new \stdClass,
            'list' => [],
            'nested' => [(object) ['z' => new \stdClass, 'a' => ['items' => [], 'map' => (object) ['1' => 'one', '0' => 'zero']]]],
        ]));
        $this->application()->make(DatabaseManager::class)->purge('pgsql');
        $this->application()->forgetInstance(OperationRunner::class);
        $this->application()->forgetInstance(IdempotencyStore::class);
        $replay = $this->runner()->run($this->spec(key: 'k-json-shapes'), static fn (): Receipt => throw new LogicException('no second body'));

        $expected = '{"list":[],"metadata":{},"nested":[{"a":{"items":[],"map":{"0":"zero","1":"one"}},"z":{}}]}';
        self::assertSame($expected, json_encode($first->body, JSON_THROW_ON_ERROR));
        self::assertSame($expected, json_encode($replay->body, JSON_THROW_ON_ERROR));
        self::assertSame(json_encode($first->result(), JSON_THROW_ON_ERROR), json_encode($replay->result(), JSON_THROW_ON_ERROR));
        self::assertTrue($replay->replayed);
        self::assertSame($first->receiptId, $replay->receiptId);
        self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-json-shapes']));
    }

    public function test_receipts_are_immutable_once_completed_and_never_deleted(): void
    {
        $this->runner()->run($this->spec(key: 'k-frozen'), static fn (): Receipt => self::receipt());
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_idempotency_receipt SET result = '{}' WHERE command_key = 'k-frozen'"));
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_idempotency_receipt SET digest = 'x' WHERE command_key = 'k-frozen'"));
        self::assertSame('55000', self::sqlState($owner, "DELETE FROM billium_idempotency_receipt WHERE command_key = 'k-frozen'"));
        $runtime = TestEnvironment::pdo();
        self::assertSame('42501', self::sqlState($runtime, "DELETE FROM billium_idempotency_receipt WHERE command_key = 'k-frozen'"));
        self::assertSame('42501', self::sqlState($runtime, "UPDATE billium_idempotency_receipt SET digest = 'x' WHERE command_key = 'k-frozen'"));
    }

    public function test_claim_and_complete_require_the_root_transaction(): void
    {
        $store = $this->application()->make(IdempotencyStore::class);
        try {
            $store->claim($this->callers->current(), 'ledger.x', 'k', 'd');
            self::fail('claim outside a root Tx');
        } catch (LogicException $exception) {
            self::assertStringContainsString('root Tx', $exception->getMessage());
        }
        $this->expectException(LogicException::class);
        $store->complete(1, StoredOutcome::accepted(self::receipt()));
    }
}
