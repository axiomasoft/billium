<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\JournalEvent;
use Billium\Testkit\Fixtures\ProcessBarrier;
use Billium\Testkit\Fixtures\ProcessIdentity;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('PB-19')]
final class NamespaceRetentionTest extends ExecutionTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = Fixture::tempPath();
        Fixture::seedMerchant($this->application());
        Fixture::host($this->application(), $this->path);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.host', $this->path.'.control'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_authenticated_key_has_independent_golden_bytes_and_sale_authorize_cannot_reinterpret_the_intent(): void
    {
        self::assertSame('sha256:8e836a587819c3e0680f497fd487b3feb6865a7999e4b7f3ff71192fb210a2f0', PaymentIdentity::commandKey(Fixture::scope(), 'charge', 'checkout', Fixture::key(1)));
        $commands = $this->application()->make(RootPaymentCommands::class);
        $first = $commands->admit(Fixture::scope(), Fixture::wire())->orThrow();
        self::assertSame(1, $this->rows('billium_execution_operation', "requested_minor=100 AND currency='RUB' AND state='admitted'"));
        self::assertSame(1, $this->rows('billium_execution_business_binding', "binding->'origin'->>'policyVersion'='terms-v1' AND binding->'origin'->>'periodRef'='period-a'"));
        foreach ([
            ['schema' => 'payment.authorize.v1'],
            ['money' => ['amount' => '1.01', 'currency' => 'RUB']],
            ['source' => ['context' => 'checkout', 'intentRef' => 'intent-a', 'orderRef' => 'another-order']],
            ['bindingRevision' => 'merchant-changed'],
        ] as $change) {
            try {
                $commands->admit(Fixture::scope(), Fixture::wire($change + ['commandKey' => Fixture::key(2)]))->orThrow();
                self::fail('Changed commercial terms reused the original purchase');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            }
        }
        $audit = $this->table('SELECT actor_ref,command_kind,problem_code,original_operation_ref,semantic_digest,recorded_at FROM billium_execution_admission_conflict ORDER BY audit_ref');
        self::assertCount(4, $audit);
        foreach ($audit as $row) {
            self::assertSame('actor-1', $row['actor_ref']);
            self::assertSame('root-a', $row['original_operation_ref']);
            self::assertSame(ProblemCode::IdempotencyConflict->value, $row['problem_code']);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', self::text($row['semantic_digest']));
            self::assertNotNull($row['recorded_at']);
        }
        self::assertSame('root-a', $first->body['operationRef']);
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(1, $this->rows('billium_execution_dispatch_work'));
    }

    public function test_kill_before_txa_commit_rolls_back_identity_capacity_attempt_receipt_and_wakeup(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $key = random_int(1, 2147483647);
        $owner->exec('SELECT pg_advisory_lock('.$key.')');
        $owner->exec("CREATE FUNCTION public.billium_test_pause_admission() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN PERFORM pg_advisory_xact_lock(".$key."); RETURN NEW; END'");
        $owner->exec('CREATE TRIGGER billium_test_pause BEFORE INSERT ON billium_execution_dispatch_work FOR EACH ROW EXECUTE FUNCTION billium_test_pause_admission()');
        $child = UnknownResultTest::start($this->path, 'after-txa');
        try {
            $until = microtime(true) + 15;
            do {
                $waiting = self::integer(self::pdoValue($owner, "SELECT count(*) FROM pg_locks WHERE locktype='advisory' AND objid=".$key.' AND NOT granted'));
                if ($waiting === 1) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $until);
            self::assertSame(1, $waiting);
            $child->kill();
            self::assertNotSame(0, $child->wait());
        } finally {
            $owner->exec('SELECT pg_advisory_unlock('.$key.')');
            $owner->exec('DROP TRIGGER billium_test_pause ON billium_execution_dispatch_work');
            $owner->exec('DROP FUNCTION public.billium_test_pause_admission()');
        }
        foreach (['business_binding', 'capacity', 'operation', 'attempt', 'dispatch_work'] as $table) {
            self::assertSame(0, $this->rows('billium_execution_'.$table));
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='payment.execute.v1'"));
        self::assertSame(0, UnknownResultTest::events($this->path, 'provider.call'));
        $this->application()->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        self::assertSame(1, $this->rows('billium_execution_attempt'));
    }

    public static function closeRace(string $path, string $action): void
    {
        $app = Fixture::workerApp($action === 'close' ? 900 : 901);
        $control = new DurableJournal($path.'.control');
        $barrier = new ProcessBarrier($control);
        $control->append(new JournalEvent($action.'-backend', ['backend' => self::integer($app->make(DatabaseManager::class)->connection()->scalar('SELECT pg_backend_pid()'))], ProcessIdentity::current()));
        if ($action === 'close') {
            $app->make(OperationRunner::class)->run(new OperationSpec('execution.close_namespace', 'seller-a', 'test', FinancialMode::Active,
                ModuleOperationClass::FrozenIntentCompletion, ['execution', 'platform'], financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium')),
                static function (TransactionContext $ctx) use ($app, $barrier): PlatformReceipt {
                    $app->make(ExecutionStore::class)->transitionNamespace($ctx, Fixture::scope(), NamespaceState::Closed, 1, 'sealed-history');
                    $barrier->arrive('closed-uncommitted');
                    $barrier->await('commit-close', 1, 20_000);

                    return self::receipt();
                })->orThrow();

            return;
        }
        Fixture::host($app, $path);
        $barrier->arrive('starting-admission');
        try {
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            self::fail('Concurrent namespace closure admitted an effect');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::KeyExpired, $exception->problem);
            $control->append(new JournalEvent('refusal', ['problem' => $exception->problem->value], ProcessIdentity::current()));
        }
    }

    public function test_namespace_closure_and_admission_serialize_on_the_same_row_before_effect_creation(): void
    {
        $entry = 'require "vendor/autoload.php"; Billium\\Execution\\Tests\\Feature\\NamespaceRetentionTest::closeRace($argv[1],$argv[2]);';
        $control = new DurableJournal($this->path.'.control');
        $barrier = new ProcessBarrier($control);
        $closer = new ChildProcess([PHP_BINARY, '-r', $entry, $this->path, 'close']);
        $barrier->await('closed-uncommitted', 1, 15_000);
        $arrival = new ChildProcess([PHP_BINARY, '-r', $entry, $this->path, 'admit']);
        try {
            $barrier->await('starting-admission', 1, 15_000);
            $writer = array_find($control->records(), static fn (object $event): bool => $event instanceof JournalEvent && $event->kind === 'close-backend');
            $reader = array_find($control->records(), static fn (object $event): bool => $event instanceof JournalEvent && $event->kind === 'admit-backend');
            self::assertInstanceOf(JournalEvent::class, $writer);
            self::assertInstanceOf(JournalEvent::class, $reader);
            $writerId = self::integer($writer->data['backend']);
            $readerId = self::integer($reader->data['backend']);
            self::assertNotSame($writerId, $readerId);
            $owner = TestEnvironment::pdo(owner: true);
            $end = microtime(true) + 8;
            do {
                $waiting = self::pdoValue($owner, 'SELECT '.$writerId.' = ANY(pg_blocking_pids('.$readerId.'))');
                if ($waiting === true) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $end);
            self::assertTrue($waiting);
        } finally {
            $barrier->arrive('commit-close');
        }
        self::assertSame(0, $closer->wait());
        self::assertSame(0, $arrival->wait());
        self::assertSame(1, UnknownResultTest::events($this->path.'.control', 'refusal'));
        self::assertSame(0, $this->rows('billium_execution_business_binding'));
        self::assertSame(0, $this->rows('billium_execution_attempt'));
        self::assertSame(0, UnknownResultTest::events($this->path, 'provider.call'));
    }

    public function test_pruned_receipts_and_closed_namespace_resolve_original_without_reacquiring_host_or_adapter(): void
    {
        $scope = Fixture::scope();
        $commands = $this->application()->make(RootPaymentCommands::class);
        $first = $commands->admit($scope, Fixture::wire())->orThrow();
        $this->runner()->run($this->spec(kind: 'execution.close_namespace', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($scope): PlatformReceipt {
            $this->application()->make(ExecutionStore::class)->transitionNamespace($ctx, $scope, NamespaceState::Closed, 1, 'archived-history-receipt');

            return self::receipt();
        });
        $this->owner()->statement('TRUNCATE billium_idempotency_receipt');
        Fixture::host($this->application(), $this->path, [Fixture::origin(operation: 'root-b')], qualified: false);
        $restored = $commands->admit($scope, Fixture::wire(['commandKey' => Fixture::key(3)]))->orThrow();
        self::assertSame($first->body['receiptRef'], $restored->body['receiptRef']);
        self::assertSame($first->body['evidenceRefs'], $restored->body['evidenceRefs']);
        Fixture::host($this->application(), $this->path, [Fixture::origin(operation: 'root-b')]);
        try {
            $commands->admit($scope, Fixture::wire(['commandKey' => Fixture::key(4), 'operationRef' => 'root-b']))->orThrow();
            self::fail('Closed namespace admitted fresh effect');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::KeyExpired, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_execution_attempt'));
        self::assertSame(1, $this->rows('billium_execution_business_binding', "retention_policy='execution-business-identity-indefinite-v1' AND retention_deadline_at IS NULL"));
    }

    public function test_missing_host_qualification_and_foreign_selectors_leave_no_attempt_or_money(): void
    {
        $scope = Fixture::scope();
        Fixture::host($this->application(), $this->path, qualified: false);
        $commands = $this->application()->make(RootPaymentCommands::class);
        foreach ([
            [$scope, [], ProblemCode::CapabilityUnsupported],
            [$scope, ['source' => ['context' => "checkout\0altered", 'intentRef' => 'intent-a']], ProblemCode::ValidationInvalid],
            [$scope, ['executionProfile' => 'standalone-billium'], ProblemCode::AuthDenied],
            [$scope, ['sellerRef' => 'seller-b'], ProblemCode::AuthDenied],
            [new ExecutionScope($scope->installationRef, $scope->sellerRef, $scope->environment, 'other-owner'), [], ProblemCode::AuthDenied],
        ] as [$selection, $change, $error]) {
            try {
                $commands->admit($selection, Fixture::wire($change))->orThrow();
                self::fail('Unqualified or foreign effect admitted');
            } catch (ProblemException $e) {
                self::assertSame($error, $e->problem);
            }
        }
        $this->callers->set(new Caller('seller-a', 'test', self::INSTALLATION, 'source-only', ['remote-billium', 'source-a'], []));
        try {
            $commands->admit($scope, Fixture::wire())->orThrow();
            self::fail('Source emitter grant became business authority');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }
        foreach (['operation', 'attempt', 'business_binding', 'reservation', 'dispatch_work'] as $table) {
            self::assertSame(0, $this->rows('billium_execution_'.$table));
        }
    }
}
