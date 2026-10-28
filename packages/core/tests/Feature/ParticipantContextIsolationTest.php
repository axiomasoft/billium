<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Caller;
use Billium\Core\Data\LockRequest;
use Billium\Core\Data\OperationSpec;
use Billium\Core\Data\Receipt;
use Billium\Core\Enums\LockRank;
use Billium\Core\Exceptions\LockOrderViolation;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Data\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\IssueCreditNote;
use Billium\Ledger\Data\IssueInvoice;
use Billium\Ledger\Data\RecordObligation;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\OutboxEvent;
use Billium\Platform\Tests\Concurrency\Scenarios\RootOperationScenario;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * D60/INV-01: a TransactionContext is the capability of exactly one root attempt on one connection. Typed participants
 * (doubles here, until the owning modules exist) act only through it, only for admitted modules, only in the global
 * lock order, and never start a runner.
 */
#[Group('D60')]
#[Group('INV-01')]
final class ParticipantContextIsolationTest extends PlatformTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'documents']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_participants_compose_one_atomic_root(): void
    {
        $outcome = $this->runner()->run($this->spec(key: 'k-compose', modules: ['documents', 'ledger', 'platform']), function (TransactionContext $context): Receipt {
            $obligation = $this->participant('ledger', $context, 'obligation');
            $number = $this->participant('documents', $context, 'legal number');
            $this->application()->make(Outbox::class)->record($context, new OutboxEvent('592CRECWGJF5QEGBY35D1HF2J9', 'invoice.issued.v1', 'billium.event.v1', 'invoice:1', 1, new DateTimeImmutable, ['number' => $number]));

            return self::receipt(['obligation' => $obligation, 'number' => $number, 'generations' => $context->admittedModules()]);
        });
        self::assertSame(['documents' => 1, 'ledger' => 1, 'platform' => 1], $outcome->body['generations']);
        self::assertSame(2, $this->rows('billium_test_effect'));
        self::assertSame(1, $this->rows('billium_outbox_event'));
        self::assertSame(1, $this->rows('billium_money_commit_log'));
    }

    public function test_a_failing_participant_rolls_back_every_participant(): void
    {
        try {
            $this->runner()->run($this->spec(key: 'k-atomic', modules: ['documents', 'ledger', 'platform']), function (TransactionContext $context): Receipt {
                $this->participant('ledger', $context, 'obligation');
                $this->participant('documents', $context, 'legal number');

                throw new RuntimeException('numbering failed');
            });
            self::fail('failure propagates');
        } catch (RuntimeException $exception) {
            self::assertSame('numbering failed', $exception->getMessage());
        }
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind NOT LIKE 'platform.%'"));
    }

    public function test_stale_contexts_from_a_committed_or_retried_root_are_refused(): void
    {
        $contexts = [];
        $this->runner()->run($this->spec(), static function (TransactionContext $context) use (&$contexts): Receipt {
            $contexts[] = $context;
            if (count($contexts) === 1) {
                throw new LockPlanChanged('retry');
            }

            return self::receipt();
        });
        [$retried, $committed] = $contexts;
        foreach (['retried' => $retried, 'committed' => $committed] as $case => $stale) {
            try {
                $this->participant('ledger', $stale, $case);
                self::fail("{$case} context must be refused");
            } catch (LogicException $exception) {
                self::assertStringContainsString('Stale or foreign TransactionContext', $exception->getMessage(), $case);
            }
        }

        // A context of an earlier root is stale inside a later root as well.
        $this->expectRefusal('Stale or foreign TransactionContext', function () use ($committed): void {
            $this->runner()->run($this->spec(), function () use ($committed): Receipt {
                $this->participant('ledger', $committed, 'foreign root');

                return self::receipt();
            });
        });
    }

    public function test_foreign_module_and_wrong_connection_are_refused(): void
    {
        $this->expectRefusal('outside the admitted closure', function (): void {
            $this->runner()->run($this->spec(modules: ['ledger', 'platform']), function (TransactionContext $context): Receipt {
                $this->participant('documents', $context, 'not admitted');

                return self::receipt();
            });
        });
        $this->expectRefusal('belongs to connection pgsql', function (): void {
            $this->runner()->run($this->spec(), function (TransactionContext $context): Receipt {
                $context->assertParticipant('ledger', 'billium_owner');

                return self::receipt();
            });
        });
        $this->expectRefusal('incomplete module closure', function (): void {
            $this->runner()->run($this->spec(modules: ['ledger']), static fn (): Receipt => self::receipt());
        });
        self::assertSame(0, $this->rows('billium_test_effect'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_replacing_the_root_transaction_on_the_same_pdo_invalidates_every_capability(bool $rawPdo): void
    {
        $callbacks = 0;
        $this->expectRefusal('Stale or foreign TransactionContext', function () use ($rawPdo, &$callbacks): void {
            $this->runner()->run($this->spec(), function (TransactionContext $context) use ($rawPdo, &$callbacks): Receipt {
                self::effect($context, 'rolled back original');
                $context->afterCommit(static function () use (&$callbacks): void {
                    $callbacks++;
                });
                $db = self::connection($context->connectionName());
                $pdo = $db->getPdo();
                if ($rawPdo) {
                    $pdo->rollBack();
                    $pdo->beginTransaction();
                    $pdo->exec('SAVEPOINT trans2');
                } else {
                    $db->rollBack(0);
                    $db->beginTransaction();
                    $db->beginTransaction();
                }
                self::assertSame($pdo, $db->getPdo());
                self::assertSame(2, $db->transactionLevel());

                // A separate process can now seal: the original financial-session and module locks are gone.
                $sealed = ConcurrencyStand::run(RootOperationScenario::class, 1, ['action' => 'seal', 'ref' => 'seal-released-root']);
                self::assertSame('sealed', self::ok($sealed[0])['state']);
                self::assertFalse($context->isActive());
                $this->expectRefusal('Stale TransactionContext', fn () => $context->assertActive());
                $this->expectRefusal('Stale TransactionContext', fn () => $context->assertParticipant('ledger'));
                $this->expectRefusal('Stale or foreign TransactionContext', fn () => $this->runner()->assertCurrent($context));
                $this->expectRefusal('Stale or foreign TransactionContext', fn () => $this->participant('ledger', $context, 'stale'));
                $this->expectRefusal('Stale or foreign TransactionContext', fn () => $this->application()->make(Outbox::class)->record(
                    $context, new OutboxEvent('2BDYD2CNSX9JRCTF2MJ4JV779V', 'ledger.stale', 'billium.event.v1', 'x:1', 1, new DateTimeImmutable, []),
                ));

                // Even if the body swallows every capability refusal, the runner cannot accept its replacement Tx.
                return self::receipt();
            });
        });
        self::assertSame(0, $callbacks);
        self::assertSame(0, self::connection('pgsql')->transactionLevel());
        self::assertFalse(self::connection('pgsql')->getPdo()->inTransaction());
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
        self::assertSame('sealed', $this->installation()->currentSession(self::INSTALLATION)?->state);
    }

    public function test_participants_cannot_start_a_nested_runner_or_break_the_lock_order(): void
    {
        $this->expectRefusal('root-only', function (): void {
            $this->runner()->run($this->spec(), function (TransactionContext $context): Receipt {
                $this->participant('ledger', $context, 'before nesting');
                $this->runner()->run($this->spec(key: 'k-nested'), static fn (): Receipt => self::receipt());

                return self::receipt();
            });
        });
        self::assertSame(0, $this->rows('billium_test_effect'));

        $ids = [];
        $this->runner()->run($this->spec(), static function (TransactionContext $context) use (&$ids): Receipt {
            $ids = [self::effect($context, 'a'), self::effect($context, 'b')];

            return self::receipt();
        });
        try {
            $this->runner()->run($this->spec(), static function (TransactionContext $context) use ($ids): Receipt {
                self::assertSame([$ids[1]], $context->lock(new LockRequest(LockRank::Obligation, 'billium_test_effect', [$ids[1]])));
                $context->lock(new LockRequest(LockRank::SettlementIntent, 'billium_test_effect', [$ids[0]]));

                return self::receipt();
            });
            self::fail('rank 65 after rank 70 is a violation');
        } catch (LockOrderViolation) {
            $this->addToAssertionCount(1);
        }
        // Another seller's rows are never locked through the context (INV-14).
        $this->callers->as('seller-b');
        $this->runner()->run($this->spec(), static function (TransactionContext $context) use ($ids): Receipt {
            self::assertSame([], $context->lock(new LockRequest(LockRank::Obligation, 'billium_test_effect', $ids)));

            return self::receipt();
        });
    }

    public function test_forged_contexts_are_refused_by_the_runner(): void
    {
        $this->runner()->run($this->spec(), function (TransactionContext $genuine): Receipt {
            $forged = new readonly class($genuine) implements TransactionContext
            {
                public function __construct(private TransactionContext $inner) {}

                public function caller(): Caller
                {
                    return $this->inner->caller();
                }

                public function spec(): OperationSpec
                {
                    return $this->inner->spec();
                }

                public function connectionName(): string
                {
                    return $this->inner->connectionName();
                }

                public function attempt(): int
                {
                    return 1;
                }

                public function rootRef(): string
                {
                    return 'forged';
                }

                public function sessionEpoch(): int
                {
                    return 1;
                }

                public function admittedModules(): array
                {
                    return ['documents' => 1, 'ledger' => 1, 'platform' => 1];
                }

                public function moduleGeneration(string $moduleId): int
                {
                    return 1;
                }

                public function isActive(): bool
                {
                    return true;
                }

                public function assertActive(?string $connectionName = null): void {}

                public function assertParticipant(string $moduleId, ?string $connectionName = null): void {}

                public function lock(LockRequest ...$requests): array
                {
                    return [];
                }

                public function afterCommit(\Closure $callback): void {}
            };
            // A look-alike that waves every check through is still not the live root of the runner.
            $this->expectRefusal('Stale or foreign TransactionContext', fn () => $this->participant('ledger', $forged, 'forged'));
            $this->expectRefusal('Stale or foreign TransactionContext', fn () => $this->application()->make(Outbox::class)->record(
                $forged, new OutboxEvent('0A1WMK60N6W2PR5YQ0YAVY7D1B', 'ledger.forged', 'billium.event.v1', 'x:1', 1, new DateTimeImmutable, []),
            ));

            return self::receipt();
        });
        self::assertSame(0, $this->rows('billium_test_effect'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
    }

    public function test_the_context_and_participant_port_expose_no_constructor_sql_or_connection(): void
    {
        $context = new ReflectionClass(TransactionContext::class);
        self::assertTrue($context->isInterface(), 'no public constructor: only the runner mints a context');
        foreach ($context->getMethods() as $method) {
            self::assertNotContains($method->getName(), ['connection', 'getPdo', 'select', 'statement', 'unprepared']);
            $type = $method->getReturnType();
            self::assertFalse($type instanceof ReflectionNamedType && is_a($type->getName(), ConnectionInterface::class, true));
        }

        $participants = new ReflectionClass(LedgerParticipants::class);
        $commands = [
            'recordObligation' => RecordObligation::class,
            'declareSettlementIntent' => DeclareSettlementIntent::class,
            'issueInvoice' => IssueInvoice::class,
            'issueCreditNote' => IssueCreditNote::class,
            'adjustObligation' => AdjustObligation::class,
            'closeSettlementIntentNoEffect' => CloseSettlementIntentNoEffect::class,
        ];
        self::assertCount(count($commands), $participants->getMethods());
        foreach ($commands as $name => $command) {
            self::assertTrue($participants->hasMethod($name), $name);
            self::assertTrue(class_exists($command), $command);
            $method = $participants->getMethod($name);
            self::assertSame($command, (string) $method->getParameters()[1]->getType());
            self::assertSame(Receipt::class, (string) $method->getReturnType());
            self::assertSame($name === 'issueInvoice' ? 3 : 2, $method->getNumberOfRequiredParameters());
        }
        $legalDocument = $participants->getMethod('issueInvoice')->getParameters()[2];
        self::assertSame('legalDocumentRef', $legalDocument->getName());
        self::assertSame('string', (string) $legalDocument->getType());
        foreach ($participants->getMethods() as $method) {
            self::assertNotContains($method->getName(), ['connection', 'getPdo', 'select', 'statement', 'unprepared']);
            $parameters = $method->getParameters();
            $first = $parameters[0]->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $first);
            self::assertSame(TransactionContext::class, $first->getName(), $method->getName());
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type);
                self::assertFalse(is_a($type->getName(), ConnectionInterface::class, true), $method->getName());
                self::assertStringNotContainsString('\\Internal\\', $type->getName());
            }
        }
    }

    /** A typed participant double of `$module`: checks the context, then writes only through its connection. */
    private function participant(string $module, TransactionContext $context, string $ref): int
    {
        $this->runner()->assertCurrent($context, $module);

        return self::effect($context, "{$module}: {$ref}");
    }

    private function expectRefusal(string $message, \Closure $action): void
    {
        try {
            $action();
            self::fail("expected refusal: {$message}");
        } catch (LogicException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
